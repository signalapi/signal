<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\MerchantMemberRepository;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decides what a user may do inside a workspace. Merchant owners/admins get
 * everything implicitly; plain merchant members act by their workspace_member
 * role: admin > editor > viewer.
 *
 * @extends Voter<string, Workspace>
 */
final class WorkspaceVoter extends Voter
{
    public const VIEW = 'WORKSPACE_VIEW';
    public const EDIT = 'WORKSPACE_EDIT';
    public const ADMIN = 'WORKSPACE_ADMIN';

    public function __construct(
        private readonly MerchantMemberRepository $merchantMembers,
        private readonly WorkspaceMemberRepository $workspaceMembers,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::ADMIN], true) && $subject instanceof Workspace;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $merchantMembership = $this->merchantMembers->findOneByUserAndMerchant($user, $subject->getMerchant());
        if (null === $merchantMembership) {
            return false;
        }
        if ($merchantMembership->canManage()) {
            return true;
        }

        $membership = $this->workspaceMembers->findOneByUserAndWorkspace($user, $subject);
        if (null === $membership) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::EDIT => $membership->canEdit(),
            self::ADMIN => $membership->isAdmin(),
            default => false,
        };
    }
}
