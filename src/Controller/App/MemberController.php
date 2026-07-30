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
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);

        // Per-user workspace access summary: user id => ["Zotlo · editor", ...].
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

    #[Route('/invite', name: 'app_member_invite', methods: ['POST'])]
    public function invite(
        Request $request,
        MerchantMemberRepository $members,
        WorkspaceRepository $workspaces,
        EntityManagerInterface $em,
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
            $this->addFlash('error', 'Geçerli bir e-posta girin.');

            return $this->redirectToRoute('app_member_index');
        }
        foreach ($members->findByMerchant($merchant) as $existing) {
            if ($existing->getUser()->getEmail() === $email) {
                $this->addFlash('error', 'Bu e-posta zaten üye.');

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
        $em->flush();

        $this->addFlash('invite_link', $this->generateUrl('app_invite_show', ['token' => $plaintext], UrlGeneratorInterface::ABSOLUTE_URL));
        $this->addFlash('success', sprintf('%s için davet oluşturuldu. Linki iletin — yalnızca şimdi gösterilir.', $email));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/invitations/{id}/rotate', name: 'app_invitation_rotate', methods: ['POST'])]
    public function rotateInvitation(string $id, Request $request, InvitationRepository $invitations, EntityManagerInterface $em): Response
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
        $this->addFlash('success', sprintf('%s için yeni davet linki üretildi.', $invitation->getEmail()));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/invitations/{id}/cancel', name: 'app_invitation_cancel', methods: ['POST'])]
    public function cancelInvitation(string $id, Request $request, InvitationRepository $invitations, EntityManagerInterface $em): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);
        $invitation = $this->findMerchantInvitation($invitations, $id);

        if (!$this->isCsrfTokenValid('invite-cancel' . $invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($invitation);
        $em->flush();
        $this->addFlash('success', 'Davet iptal edildi.');

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/{id}/role', name: 'app_member_role', methods: ['POST'])]
    public function changeRole(string $id, Request $request, MerchantMemberRepository $members, EntityManagerInterface $em): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);
        $member = $this->findMerchantMember($members, $id);

        if (!$this->isCsrfTokenValid('member-role' . $member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($member->isOwner()) {
            $this->addFlash('error', 'Owner rolü buradan değiştirilemez.');

            return $this->redirectToRoute('app_member_index');
        }

        $role = (string) $request->request->get('role');
        if (!\in_array($role, [MerchantMember::ROLE_ADMIN, MerchantMember::ROLE_MEMBER], true)) {
            throw $this->createAccessDeniedException();
        }
        $member->setRole($role);
        $em->flush();
        $this->addFlash('success', sprintf(
            '%s artık %s.',
            $member->getUser()->getName(),
            MerchantMember::ROLE_ADMIN === $role ? 'genel yönetici' : 'üye'
        ));

        return $this->redirectToRoute('app_member_index');
    }

    #[Route('/{id}/remove', name: 'app_member_remove', methods: ['POST'])]
    public function remove(string $id, Request $request, MerchantMemberRepository $members, EntityManagerInterface $em): Response
    {
        $merchant = $this->currentMerchant();
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_MEMBERS, $merchant);
        $member = $this->findMerchantMember($members, $id);

        if (!$this->isCsrfTokenValid('member-remove' . $member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($member->isOwner()) {
            $this->addFlash('error', 'Owner üyelikten çıkarılamaz.');

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
        $this->addFlash('success', sprintf('%s üyelikten çıkarıldı.', $member->getUser()->getName()));

        return $this->redirectToRoute('app_member_index');
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
