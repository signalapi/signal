<?php

namespace App\Security;

use App\Entity\Merchant;
use App\Entity\MerchantMember;
use App\Entity\User;
use App\Repository\MerchantMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Merchant-level authority: owners and company admins ("general manager") manage
 * members and the workspace inventory (create/delete); owner-only operations
 * (deleting the merchant, transferring ownership) require MERCHANT_ADMIN.
 *
 * @extends Voter<string, Merchant>
 */
final class MerchantVoter extends Voter
{
    public const MANAGE_MEMBERS = 'MERCHANT_MANAGE_MEMBERS';
    public const MANAGE_WORKSPACES = 'MERCHANT_MANAGE_WORKSPACES';
    public const ADMIN = 'MERCHANT_ADMIN';

    public function __construct(private readonly MerchantMemberRepository $merchantMembers)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE_MEMBERS, self::MANAGE_WORKSPACES, self::ADMIN], true) && $subject instanceof Merchant;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $membership = $this->merchantMembers->findOneByUserAndMerchant($user, $subject);
        if (null === $membership) {
            return false;
        }

        return match ($attribute) {
            self::MANAGE_MEMBERS, self::MANAGE_WORKSPACES => $membership->canManage(),
            self::ADMIN => MerchantMember::ROLE_OWNER === $membership->getRole(),
            default => false,
        };
    }
}
