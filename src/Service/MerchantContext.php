<?php

namespace App\Service;

use App\Entity\Merchant;
use App\Entity\MerchantMember;
use App\Entity\User;
use App\Repository\MerchantMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the "active" merchant for the logged-in user. A user can belong to
 * several merchants; the selection is remembered in the session, falling back
 * to the oldest membership.
 */
class MerchantContext
{
    private const SESSION_KEY = 'current_merchant_id';

    /** @var MerchantMember[]|null */
    private ?array $memberships = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly MerchantMemberRepository $members,
    ) {
    }

    /** @return MerchantMember[] */
    public function memberships(): array
    {
        if (null !== $this->memberships) {
            return $this->memberships;
        }

        $user = $this->security->getUser();

        return $this->memberships = $user instanceof User ? $this->members->findByUser($user) : [];
    }

    public function current(): ?Merchant
    {
        return $this->currentMembership()?->getMerchant();
    }

    /** The logged-in user's membership row for the active merchant. */
    public function currentMembership(): ?MerchantMember
    {
        $list = $this->memberships();
        if ([] === $list) {
            return null;
        }

        $sessionId = $this->requestStack->getSession()->get(self::SESSION_KEY);
        if (null !== $sessionId) {
            foreach ($list as $membership) {
                if ((string) $membership->getMerchant()->getId() === $sessionId) {
                    return $membership;
                }
            }
        }

        return $list[0];
    }

    public function remember(Merchant $merchant): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, (string) $merchant->getId());
    }
}
