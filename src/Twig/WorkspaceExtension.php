<?php

namespace App\Twig;

use App\Entity\Workspace;
use App\Service\WorkspaceContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the merchant's workspaces and the active one to templates so the
 * sidebar workspace switcher works on every page.
 */
class WorkspaceExtension extends AbstractExtension
{
    public function __construct(private readonly WorkspaceContext $context)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('merchant_workspaces', $this->workspaces(...)),
            new TwigFunction('current_workspace', $this->current(...)),
        ];
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
