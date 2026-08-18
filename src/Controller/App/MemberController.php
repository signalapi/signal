<?php

namespace App\Controller\App;

use App\Entity\Invitation;
use App\Entity\MerchantMember;
use App\Entity\WorkspaceMember;
use App\Repository\InvitationRepository;
use App\Repository\MerchantMemberRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Repository\WorkspaceRepository;
use App\Security\MerchantVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Merchant-level member management: list, invite, change role, remove.
 * Invites work with a copyable signed link; e-mail delivery can be layered on
 * later without touching the flow.
 */
#[Route('/app/settings/members')]
#[IsGranted('ROLE_USER')]
class MemberController extends AbstractAppController
{
    #[Route('', name: 'app_member_index', methods: ['GET'])]
    public function index(
        MerchantMemberRepository $members,
        InvitationRepository $invitations,
        WorkspaceRepository $workspaces,
        WorkspaceMemberRepository $workspaceMembers,
    ): Response {
        $merchant = $this->currentMerchant();
        if (!$this->isGranted(MerchantVoter::MANAGE_MEMBERS, $merchant)) {
            // A bare 403 page also hides the sidebar — including the company
            // switcher, which may lead to a merchant they *can* manage. Bounce
            // them back into the app with the reason instead.
            $this->addFlash('error', $this->translator->trans('You do not have permission to manage the users of "%name%".', ['%name%' => $merchant->getName()]));

            return $this->redirectToRoute('app_dashboard');
        }

        // Per-user workspace access summary: user id => ["Payments · editor", ...].
        $access = [];
        foreach ($workspaceMembers->findByMerchant($merchant) as $wm) {
            $access[(string) $wm->getUser()->getId()][] = $wm->getWorkspace()->getName() . ' · ' . $wm->getRole();
        }

        return $this->render('app/settings/members.html.twig', [
            'merchant' => $merchant,
            'members' => $members->findByMerchant($merchant),
            'invitations' => $invitations->findPendingByMerchant($merchant),
            'workspaces' => $workspaces->findByMerchant($merchant),
            'workspace_access' => $access,
        ]);
    }

    /**
     * Gives a personal account a company name and turns it into a team account.
     * Inviting someone does this implicitly; this is the explicit route for
     * people who want the company identity first (invoicing, branding).
     */
    #[Route('/convert', name: 'app_member_convert', methods: ['POST'])]
    public function convertToTeam(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);

        if (!$this->isCsrfTokenValid('member-convert', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('company_name'));
        if ('' === $name) {
            $this->addFlash('error', $translator->trans('Enter a company name.'));

            return $this->redirectToRoute('app_member_index');
        }

        $merchant->setName($name);
        $merchant->promoteToTeam();
        $em->flush();
        $this->addFlash('success', $translator->trans('Your account is now the "%name%" team account.', ['%name%' => $name]));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/invite', name: 'app_member_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        MerchantMemberRepository $members,
        WorkspaceRepository $workspaces,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);

        if (!$this->isCsrfTokenValid('member-invite', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $merchantRole = (string) $request->request->get('merchant_role', MerchantMember::ROLE_MEMBER);
        $workspaceRole = (string) $request->request->get('workspace_role', WorkspaceMember::ROLE_EDITOR);
        $workspaceIds = array_map('strval', (array) $request->request->all('workspace_ids'));

        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', $translator->trans('Enter a valid e-mail address.'));

            return $this->redirectToRoute('app_member_index');
        }
        foreach ($members->findByMerchant($merchant) as $existing) {
            if ($existing->getUser()->getEmail() === $email) {
                $this->addFlash('error', $translator->trans('This e-mail is already a member.'));

                return $this->redirectToRoute('app_member_index');
            }
        }

        $grants = [];
        if (MerchantMember::ROLE_MEMBER === $merchantRole && \in_array($workspaceRole, WorkspaceMember::ROLES, true)) {
            $owned = array_map(static fn ($w) => (string) $w->getId(), $workspaces->findByMerchant($merchant));
            foreach ($workspaceIds as $wsId) {
                if (\in_array($wsId, $owned, true)) {
                    $grants[] = ['workspace_id' => $wsId, 'role' => $workspaceRole];
                }
            }
        }

        $plaintext = bin2hex(random_bytes(24));

        $invitation = new Invitation();
        $invitation->setMerchant($merchant);
        $invitation->setEmail($email);
        $invitation->setMerchantRole($merchantRole);
        $invitation->setWorkspaceGrants($grants);
        $invitation->setTokenHash(hash('sha256', $plaintext));
        $invitation->setInvitedBy($this->currentUser());
        $em->persist($invitation);
        // Inviting anyone makes this a team account.
        $merchant->promoteToTeam();
        $em->flush();

        $this->addFlash('invite_link', $this->generateUrl('app_invite_show', ['token' => $plaintext], UrlGeneratorInterface::ABSOLUTE_URL));
        $this->addFlash('success', $translator->trans('Invitation created for %email%. Share the link — it is shown only now.', ['%email%' => $email]));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/invitations/{id}/rotate', name: 'app_invitation_rotate', methods: ['POST'])]
    public function rotateInvitation(string $id, Request $request, InvitationRepository $invitations, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);
        $invitation = $this->findMerchantInvitation($invitations, $id);

        if (!$this->isCsrfTokenValid('invite-rotate' . $invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $plaintext = bin2hex(random_bytes(24));
        $invitation->setTokenHash(hash('sha256', $plaintext));
        $invitation->setExpiresAt(new \DateTimeImmutable('+7 days'));
        $em->flush();

        $this->addFlash('invite_link', $this->generateUrl('app_invite_show', ['token' => $plaintext], UrlGeneratorInterface::ABSOLUTE_URL));
        $this->addFlash('success', $translator->trans('A new invitation link was generated for %email%.', ['%email%' => $invitation->getEmail()]));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/invitations/{id}/cancel', name: 'app_invitation_cancel', methods: ['POST'])]
    public function cancelInvitation(string $id, Request $request, InvitationRepository $invitations, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);
        $invitation = $this->findMerchantInvitation($invitations, $id);

        if (!$this->isCsrfTokenValid('invite-cancel' . $invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($invitation);
        $em->flush();
        $this->addFlash('success', $translator->trans('Invitation cancelled.'));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/{id}/role', name: 'app_member_role', methods: ['POST'])]
    public function changeRole(string $id, Request $request, MerchantMemberRepository $members, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);
        $member = $this->findMerchantMember($members, $id);

        if (!$this->isCsrfTokenValid('member-role' . $member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($member->isOwner()) {
            $this->addFlash('error', $translator->trans('The owner role cannot be changed here.'));

            return $this->redirectToRoute('app_member_index');
        }
        // Demoting yourself would take away this very page: the admin would be
        // locked out of member management with no way back in.
        if ($this->isSelf($member)) {
            $this->addFlash('error', $translator->trans('You cannot change your own role. Another company admin or the owner has to do it.'));

            return $this->redirectToRoute('app_member_index');
        }

        $role = (string) $request->request->get('role');
        if (!\in_array($role, [MerchantMember::ROLE_ADMIN, MerchantMember::ROLE_MEMBER], true)) {
            throw $this->createAccessDeniedException();
        }
        $member->setRole($role);
        $em->flush();
        $this->addFlash('success', $translator->trans('%name% is now %role%.', [
            '%name%' => $member->getUser()->getName(),
            '%role%' => MerchantMember::ROLE_ADMIN === $role ? $translator->trans('Company Admin') : $translator->trans('Member'),
        ]));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/{id}/remove', name: 'app_member_remove', methods: ['POST'])]
    public function remove(string $id, Request $request, MerchantMemberRepository $members, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);
        $member = $this->findMerchantMember($members, $id);

        if (!$this->isCsrfTokenValid('member-remove' . $member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($member->isOwner()) {
            $this->addFlash('error', $translator->trans('The owner cannot be removed from membership.'));

            return $this->redirectToRoute('app_member_index');
        }
        if ($this->isSelf($member)) {
            $this->addFlash('error', $translator->trans('You cannot remove yourself from the company. Another company admin or the owner has to do it.'));

            return $this->redirectToRoute('app_member_index');
        }

        // Also drop the user's per-workspace grants inside this merchant.
        $em->createQuery(
            'DELETE FROM App\Entity\WorkspaceMember wm
             WHERE wm.user = :user AND wm.workspace IN (
                 SELECT w.id FROM App\Entity\Workspace w WHERE w.merchant = :merchant
             )'
        )
            ->setParameter('user', $member->getUser()->getId(), 'uuid')
            ->setParameter('merchant', $merchant->getId(), 'uuid')
            ->execute();

        $em->remove($member);
        $em->flush();
        $this->addFlash('success', $translator->trans('%name% was removed from membership.', ['%name%' => $member->getUser()->getName()]));

        return $this->redirectToRoute('app_member_index');
    }

    /** Is this the logged-in user's own membership row? */
    private function isSelf(MerchantMember $member): bool
    {
        return $member->getUser()->getId()?->toRfc4122() === $this->currentUser()->getId()?->toRfc4122();
    }

    private function findMerchantMember(MerchantMemberRepository $members, string $id): MerchantMember
    {
        $member = Uuid::isValid($id) ? $members->find(Uuid::fromString($id)) : null;
        if (null === $member || $member->getMerchant()->getId()?->toRfc4122() !== $this->currentMerchant()->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $member;
    }

    private function findMerchantInvitation(InvitationRepository $invitations, string $id): Invitation
    {
        $invitation = Uuid::isValid($id) ? $invitations->find(Uuid::fromString($id)) : null;
        if (null === $invitation || $invitation->getMerchant()->getId()?->toRfc4122() !== $this->currentMerchant()->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $invitation;
    }
}
