<?php

namespace App\Service;

use App\Entity\CatalogApi;
use App\Entity\Merchant;
use App\Entity\User;
use App\Repository\MerchantMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may see a marketplace entry. Single source of truth for both the listing
 * (repository-level filter) and single-entry access (marketplace add, deep
 * links), so a private catalog entry can never leak through a direct URL.
 */
class CatalogVisibility
{
    public function __construct(
        private readonly Security $security,
        private readonly MerchantMemberRepository $merchantMembers,
    ) {
    }

    public function isVisibleTo(CatalogApi $api, ?User $user): bool
    {
        if (!$api->isActive()) {
            return false;
        }

        return match ($api->getVisibility()) {
            CatalogApi::VISIBILITY_PUBLIC => true,
            CatalogApi::VISIBILITY_MERCHANT => null !== $api->getOwnerMerchant()
                && null !== $user
                && $this->isMemberOf($api->getOwnerMerchant(), $user),
            CatalogApi::VISIBILITY_WORKSPACE => null !== $api->getOwnerWorkspace()
                && $this->security->isGranted('WORKSPACE_VIEW', $api->getOwnerWorkspace()),
            default => false,
        };
    }

    /**
     * The companies a user belongs to — the scope for merchant-visible entries.
     *
     * @return Merchant[]
     */
    public function merchantsOf(?User $user): array
    {
        if (null === $user) {
            return [];
        }

        return array_map(
            static fn ($membership) => $membership->getMerchant(),
            $this->merchantMembers->findByUser($user),
        );
    }

    private function isMemberOf(Merchant $merchant, User $user): bool
    {
        return null !== $this->merchantMembers->findOneByUserAndMerchant($user, $merchant);
    }
}
