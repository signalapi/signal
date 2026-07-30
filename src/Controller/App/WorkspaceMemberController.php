<?php

namespace App\Controller\App;

use App\Entity\Invitation;
use App\Entity\MerchantMember;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\InvitationRepository;
use App\Repository\MerchantMemberRepository;
use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Per-workspace access management, run by the workspace's admins: invite by
 * e-mail straight into this workspace, add existing company users, manage
 * roles. Merchant owners/admins are implicit workspace admins and never
 * appear as explicit rows here.
 */
#[Route('/app/workspaces/{workspace}/members')]
#[IsGranted('ROLE_USER')]
class WorkspaceMemberController extends AbstractAppController
{
    #[Route('', name: 'app_ws_member_index', methods: ['GET'])]
    public function index(
        Workspace $workspace,
        WorkspaceMemberRepository $members,
        MerchantMemberRepository $merchantMembers,
        InvitationRepository $invitations,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');

        $existing = $members->findByWorkspace($workspace);
        $existingIds = array_map(static fn (WorkspaceMember $m) => (string) $m->getUser()->getId(), $existing);

        // Plain merchant members without a row yet — the only ones worth adding.
        $addable = array_filter(
            $merchantMembers->findByMerchant($workspace->getMerchant()),
            static fn ($mm) => !$mm->canManage() && !\in_array((string) $mm->getUser()->getId(), $existingIds, true)
        );

        return $this->render('app/workspace/members.html.twig', [
            'workspace' => $workspace,
            'members' => $existing,
            'addable' => $addable,
            'implicit_admins' => array_filter(
                $merchantMembers->findByMerchant($workspace->getMerchant()),
                static fn ($mm) => $mm->canManage()
            ),
            'invitations' => $this->pendingForWorkspace($invitations, $workspace),
        ]);
    }

    /**
     * Invite by e-mail straight into this workspace. A brand-new e-mail joins
     * the company as a plain member seeing only this workspace; an existing
     * company user is granted access immediately, without an invite.
     */
    #[Route('/invite', name: 'app_ws_member_invite', methods: ['POST'])]
    public function invite(
        Workspace $workspace,
        Request $request,
        MerchantMemberRepository $merchantMembers,
        WorkspaceMemberRepository $members,
        InvitationRepository $invitations,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');

        if (!$this->isCsrfTokenValid('ws-invite', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $role = (string) $request->request->get('role', WorkspaceMember::ROLE_EDITOR);
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL) || !\in_array($role, WorkspaceMember::ROLES, true)) {
            $this->addFlash('error', 'Geçerli bir e-posta ve rol seçin.');

            return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
        }

        // Already in the company? Grant access directly, no invite dance.
        foreach ($merchantMembers->findByMerchant($workspace->getMerchant()) as $mm) {
            if ($mm->getUser()->getEmail() !== $email) {
                continue;
            }
            if ($mm->canManage()) {
                $this->addFlash('error', 'Bu kişi şirket yöneticisi; zaten tüm workspace\'lere erişiyor.');
            } elseif (null !== $members->findOneByUserAndWorkspace($mm->getUser(), $workspace)) {
                $this->addFlash('error', 'Bu kişi zaten workspace üyesi.');
            } else {
                $membership = new WorkspaceMember();
                $membership->setWorkspace($workspace);
                $membership->setUser($mm->getUser());
                $membership->setRole($role);
                $em->persist($membership);
                $em->flush();
                $this->addFlash('success', sprintf('%s şirkette zaten kayıtlıydı; workspace\'e eklendi (%s).', $mm->getUser()->getName(), $role));
            }

            return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
        }

        foreach ($this->pendingForWorkspace($invitations, $workspace) as $pending) {
            if ($pending->getEmail() === $email) {
                $this->addFlash('error', 'Bu e-posta için bekleyen bir davet zaten var.');

                return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
            }
        }

        $plaintext = bin2hex(random_bytes(24));

        $invitation = new Invitation();
        $invitation->setMerchant($workspace->getMerchant());
        $invitation->setEmail($email);
        $invitation->setMerchantRole(MerchantMember::ROLE_MEMBER);
        $invitation->setWorkspaceGrants([['workspace_id' => (string) $workspace->getId(), 'role' => $role]]);
        $invitation->setTokenHash(hash('sha256', $plaintext));
        $invitation->setInvitedBy($this->currentUser());
        $em->persist($invitation);
        $em->flush();

        $this->addFlash('invite_link', $this->generateUrl('app_invite_show', ['token' => $plaintext], UrlGeneratorInterface::ABSOLUTE_URL));
        $this->addFlash('success', sprintf('%s bu workspace\'e davet edildi. Linki iletin — yalnızca şimdi gösterilir.', $email));

        return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/invitations/{id}/rotate', name: 'app_ws_invitation_rotate', methods: ['POST'])]
    public function rotateInvitation(
        Workspace $workspace,
        string $id,
        Request $request,
        InvitationRepository $invitations,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $invitation = $this->findWorkspaceInvitation($invitations, $workspace, $id);

        if (!$this->isCsrfTokenValid('ws-invite-rotate' . $invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $plaintext = bin2hex(random_bytes(24));
        $invitation->setTokenHash(hash('sha256', $plaintext));
        $invitation->setExpiresAt(new \DateTimeImmutable('+7 days'));
        $em->flush();

        $this->addFlash('invite_link', $this->generateUrl('app_invite_show', ['token' => $plaintext], UrlGeneratorInterface::ABSOLUTE_URL));
        $this->addFlash('success', sprintf('%s için yeni davet linki üretildi.', $invitation->getEmail()));

        return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/invitations/{id}/cancel', name: 'app_ws_invitation_cancel', methods: ['POST'])]
    public function cancelInvitation(
        Workspace $workspace,
        string $id,
        Request $request,
        InvitationRepository $invitations,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $invitation = $this->findWorkspaceInvitation($invitations, $workspace, $id);

        if (!$this->isCsrfTokenValid('ws-invite-cancel' . $invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($invitation);
        $em->flush();
        $this->addFlash('success', 'Davet iptal edildi.');

        return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/add', name: 'app_ws_member_add', methods: ['POST'])]
    public function add(
        Workspace $workspace,
        Request $request,
        MerchantMemberRepository $merchantMembers,
        WorkspaceMemberRepository $members,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');

        if (!$this->isCsrfTokenValid('ws-member-add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $userId = (string) $request->request->get('user_id');
        $role = (string) $request->request->get('role', WorkspaceMember::ROLE_EDITOR);
        if (!Uuid::isValid($userId) || !\in_array($role, WorkspaceMember::ROLES, true)) {
            throw $this->createAccessDeniedException();
        }

        // Only users who belong to this merchant can be granted workspace access.
        $candidate = null;
        foreach ($merchantMembers->findByMerchant($workspace->getMerchant()) as $mm) {
            if ((string) $mm->getUser()->getId() === $userId) {
                $candidate = $mm->getUser();
                break;
            }
        }
        if (null === $candidate) {
            throw $this->createNotFoundException();
        }
        if (null !== $members->findOneByUserAndWorkspace($candidate, $workspace)) {
            $this->addFlash('error', 'Kullanıcı zaten bu workspace\'in üyesi.');

            return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
        }

        $membership = new WorkspaceMember();
        $membership->setWorkspace($workspace);
        $membership->setUser($candidate);
        $membership->setRole($role);
        $em->persist($membership);
        $em->flush();
        $this->addFlash('success', sprintf('%s workspace\'e eklendi (%s).', $candidate->getName(), $role));

        return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{id}/role', name: 'app_ws_member_role', methods: ['POST'])]
    public function changeRole(
        Workspace $workspace,
        string $id,
        Request $request,
        WorkspaceMemberRepository $members,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $member = $this->findMember($members, $workspace, $id);

        if (!$this->isCsrfTokenValid('ws-member-role' . $member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $role = (string) $request->request->get('role');
        if (!\in_array($role, WorkspaceMember::ROLES, true)) {
            throw $this->createAccessDeniedException();
        }
        $member->setRole($role);
        $em->flush();
        $this->addFlash('success', sprintf('%s artık %s.', $member->getUser()->getName(), $role));

        return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{id}/remove', name: 'app_ws_member_remove', methods: ['POST'])]
    public function remove(
        Workspace $workspace,
        string $id,
        Request $request,
        WorkspaceMemberRepository $members,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'admin');
        $member = $this->findMember($members, $workspace, $id);

        if (!$this->isCsrfTokenValid('ws-member-remove' . $member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($member);
        $em->flush();
        $this->addFlash('success', sprintf('%s workspace\'ten çıkarıldı.', $member->getUser()->getName()));

        return $this->redirectToRoute('app_ws_member_index', ['workspace' => $workspace->getId()]);
    }

    /**
     * Pending invitations that grant access to this workspace.
     *
     * @return Invitation[]
     */
    private function pendingForWorkspace(InvitationRepository $invitations, Workspace $workspace): array
    {
        $wsId = (string) $workspace->getId();

        return array_values(array_filter(
            $invitations->findPendingByMerchant($workspace->getMerchant()),
            static fn (Invitation $i) => \in_array($wsId, array_column($i->getWorkspaceGrants(), 'workspace_id'), true)
        ));
    }

    /**
     * A workspace admin may only manage invitations scoped to exactly this
     * workspace — company-wide invites stay with the company admins.
     */
    private function findWorkspaceInvitation(InvitationRepository $invitations, Workspace $workspace, string $id): Invitation
    {
        $invitation = Uuid::isValid($id) ? $invitations->find(Uuid::fromString($id)) : null;
        if (null === $invitation
            || $invitation->getMerchant()->getId()?->toRfc4122() !== $workspace->getMerchant()->getId()?->toRfc4122()
            || MerchantMember::ROLE_MEMBER !== $invitation->getMerchantRole()
            || [(string) $workspace->getId()] !== array_column($invitation->getWorkspaceGrants(), 'workspace_id')) {
            throw $this->createNotFoundException();
        }

        return $invitation;
    }

    private function findMember(WorkspaceMemberRepository $members, Workspace $workspace, string $id): WorkspaceMember
    {
        $member = Uuid::isValid($id) ? $members->find(Uuid::fromString($id)) : null;
        if (null === $member || $member->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $member;
    }
}
