<?php

namespace App\Twig;

use App\Entity\Merchant;
use App\Entity\MerchantMember;
use App\Entity\Workspace;
use App\Service\MerchantContext;
use App\Service\WorkspaceContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the merchant's workspaces and the active one to templates so the
 * sidebar workspace and merchant switchers work on every page.
 */
class WorkspaceExtension extends AbstractExtension
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly MerchantContext $merchantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('merchant_workspaces', $this->workspaces(...)),
            new TwigFunction('current_workspace', $this->current(...)),
            new TwigFunction('merchant_memberships', $this->memberships(...)),
            new TwigFunction('current_merchant', $this->currentMerchant(...)),
        ];
    }

    /** @return MerchantMember[] */
    public function memberships(): array
    {
        return $this->merchantContext->memberships();
    }

    public function currentMerchant(): ?Merchant
    {
        return $this->merchantContext->current();
    }

    /**
     * @return Workspace[]
     */
    public function workspaces(): array
    {
        return $this->context->list();
    }

    public function current(): ?Workspace
    {
        return $this->context->current();
    }
}
