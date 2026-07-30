<?php

namespace App\Controller;

use App\Entity\Invitation;
use App\Entity\MerchantMember;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use App\Repository\InvitationRepository;
use App\Repository\MerchantMemberRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Repository\WorkspaceRepository;
use App\Service\MerchantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public landing for invite links. The plaintext token lives only in the URL;
 * lookup is by its SHA-256 hash. Existing accounts accept after logging in,
 * new e-mails register inline and land in the merchant directly.
 */
#[Route('/invite')]
class InvitationController extends AbstractController
{
    #[Route('/{token}', name: 'app_invite_show', methods: ['GET'])]
    public function show(string $token, InvitationRepository $invitations, UserRepository $users): Response
    {
        $invitation = $invitations->findOneByToken($token);
        $state = $this->resolveState($invitation, $users);

        return $this->render('security/invite.html.twig', [
            'invitation' => $invitation,
            'state' => $state,
            'token' => $token,
            'errors' => [],
            'old' => ['name' => ''],
        ]);
    }

    #[Route('/{token}/accept', name: 'app_invite_accept', methods: ['POST'])]
    public function accept(
        string $token,
        Request $request,
        InvitationRepository $invitations,
        UserRepository $users,
        MerchantMemberRepository $merchantMembers,
        WorkspaceMemberRepository $workspaceMembers,
        WorkspaceRepository $workspaces,
        MerchantContext $merchantContext,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $invitation = $invitations->findOneByToken($token);
        if ('accept' !== $this->resolveState($invitation, $users)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('invite-accept', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->apply($invitation, $user, $merchantMembers, $workspaceMembers, $workspaces, $em);
        $merchantContext->remember($invitation->getMerchant());
        $this->addFlash('success', $translator->trans('You have joined the "%name%" merchant.', ['%name%' => $invitation->getMerchant()->getName()]));

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/{token}/register', name: 'app_invite_register', methods: ['POST'])]
    public function register(
        string $token,
        Request $request,
        InvitationRepository $invitations,
        UserRepository $users,
        MerchantMemberRepository $merchantMembers,
        WorkspaceMemberRepository $workspaceMembers,
        WorkspaceRepository $workspaces,
        MerchantContext $merchantContext,
        UserPasswordHasherInterface $hasher,
        Security $security,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $invitation = $invitations->findOneByToken($token);
        if ('register' !== $this->resolveState($invitation, $users)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('invite-register', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $errors = [];
        $name = trim((string) $request->request->get('name'));
        $password = (string) $request->request->get('password');
        $confirm = (string) $request->request->get('password_confirm');

        if ('' === $name) {
            $errors[] = $translator->trans('Full name is required.');
        }
        if (mb_strlen($password) < 8) {
            $errors[] = $translator->trans('Password must be at least 8 characters.');
        } elseif ($password !== $confirm) {
            $errors[] = $translator->trans('Passwords do not match.');
        }

        if ([] !== $errors) {
            return $this->render('security/invite.html.twig', [
                'invitation' => $invitation,
                'state' => 'register',
                'token' => $token,
                'errors' => $errors,
                'old' => ['name' => $name],
            ]);
        }

        $user = new User();
        $user->setName($name);
        $user->setEmail($invitation->getEmail());
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);

        $this->apply($invitation, $user, $merchantMembers, $workspaceMembers, $workspaces, $em);
        $merchantContext->remember($invitation->getMerchant());
        $security->login($user, 'form_login', 'main');
        $this->addFlash('success', $translator->trans('Welcome! You have joined the "%name%" merchant.', ['%name%' => $invitation->getMerchant()->getName()]));

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Which face of the invite page applies:
     * invalid | accepted | expired | login (account exists, not logged in as it)
     * | accept (logged in as invitee) | register (no account yet).
     */
    private function resolveState(?Invitation $invitation, UserRepository $users): string
    {
        if (null === $invitation) {
            return 'invalid';
        }
        if (null !== $invitation->getAcceptedAt()) {
            return 'accepted';
        }
        if ($invitation->isExpired()) {
            return 'expired';
        }

        $account = $users->findOneBy(['email' => $invitation->getEmail()]);
        $current = $this->getUser();

        if ($current instanceof User && $current->getEmail() === $invitation->getEmail()) {
            return 'accept';
        }
        if (null !== $account) {
            return 'login';
        }

        return 'register';
    }

    private function apply(
        Invitation $invitation,
        User $user,
        MerchantMemberRepository $merchantMembers,
        WorkspaceMemberRepository $workspaceMembers,
        WorkspaceRepository $workspaces,
        EntityManagerInterface $em,
    ): void {
        if (null === $merchantMembers->findOneByUserAndMerchant($user, $invitation->getMerchant())) {
            $membership = new MerchantMember();
            $membership->setMerchant($invitation->getMerchant());
            $membership->setUser($user);
            $membership->setRole($invitation->getMerchantRole());
            $em->persist($membership);
        }

        foreach ($invitation->getWorkspaceGrants() as $grant) {
            if (!Uuid::isValid($grant['workspace_id'] ?? '')) {
                continue;
            }
            $workspace = $workspaces->find(Uuid::fromString($grant['workspace_id']));
            if (null === $workspace
                || $workspace->getMerchant()->getId()?->toRfc4122() !== $invitation->getMerchant()->getId()?->toRfc4122()
                || !\in_array($grant['role'] ?? '', WorkspaceMember::ROLES, true)
                || null !== $workspaceMembers->findOneByUserAndWorkspace($user, $workspace)) {
                continue;
            }
            $wsMembership = new WorkspaceMember();
            $wsMembership->setWorkspace($workspace);
            $wsMembership->setUser($user);
            $wsMembership->setRole($grant['role']);
            $em->persist($wsMembership);
        }

        $invitation->markAccepted();
        $em->flush();
    }
}
