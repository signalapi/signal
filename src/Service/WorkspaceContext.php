<?php

namespace App\Service;

use App\Entity\Merchant;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\WorkspaceMemberRepository;
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
        private readonly WorkspaceMemberRepository $workspaceMembers,
        private readonly MerchantContext $merchantContext,
    ) {
    }

    public function merchant(): ?Merchant
    {
        return $this->merchantContext->current();
    }

    /**
     * The workspaces the user may see: all of them for merchant owners/admins,
     * only the explicitly-granted ones for plain members.
     *
     * @return Workspace[]
     */
    public function list(): array
    {
        $membership = $this->merchantContext->currentMembership();
        if (null === $membership) {
            return [];
        }

        if ($membership->canManage()) {
            return $this->workspaces->findByMerchant($membership->getMerchant());
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->workspaceMembers->findWorkspacesForUser($user, $membership->getMerchant());
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
