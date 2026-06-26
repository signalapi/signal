<?php

namespace App\Service;

use App\Entity\Merchant;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the "active" workspace for the logged-in merchant. The selection is
 * remembered in the session so the panel always opens on the last-used workspace.
 */
class WorkspaceContext
{
    private const SESSION_KEY = 'current_workspace_id';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly WorkspaceRepository $workspaces,
    ) {
    }

    public function merchant(): ?Merchant
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getMerchant() : null;
    }

    /**
     * @return Workspace[]
     */
    public function list(): array
    {
        $merchant = $this->merchant();

        return null === $merchant ? [] : $this->workspaces->findByMerchant($merchant);
    }

    public function remember(Workspace $workspace): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, (string) $workspace->getId());
    }

    /**
     * The active workspace: the one remembered in the session (if still owned),
     * otherwise the most recent one, otherwise null.
     */
    public function current(): ?Workspace
    {
        $list = $this->list();
        if ([] === $list) {
            return null;
        }

        $sessionId = $this->requestStack->getSession()->get(self::SESSION_KEY);
        if (null !== $sessionId) {
            foreach ($list as $workspace) {
                if ((string) $workspace->getId() === $sessionId) {
                    return $workspace;
                }
            }
        }

        return $list[0];
    }
}
