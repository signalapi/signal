<?php

namespace App\Controller\App;

use App\Entity\Merchant;
use App\Entity\User;
use App\Entity\Workspace;
use App\Service\MerchantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base controller for the merchant-facing app. Centralises tenant ownership checks
 * so a merchant can only ever touch its own data.
 */
abstract class AbstractAppController extends AbstractController
{
    protected MerchantContext $merchantContext;

    #[Required]
    public function setMerchantContext(MerchantContext $merchantContext): void
    {
        $this->merchantContext = $merchantContext;
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    protected function currentMerchant(): Merchant
    {
        $merchant = $this->merchantContext->current();

        if (null === $merchant) {
            throw $this->createAccessDeniedException('Hesabınız bir merchant ile ilişkili değil.');
        }

        return $merchant;
    }

    /**
     * Grants access via WorkspaceVoter: $permission is 'view', 'edit' or 'admin'.
     */
    protected function assertWorkspace(Workspace $workspace, string $permission = 'view'): Workspace
    {
        $this->denyAccessUnlessGranted(
            'WORKSPACE_' . strtoupper($permission),
            $workspace,
            'Bu workspace üzerinde yetkiniz yok.'
        );

        // A workspace of another merchant can be opened via URL when the user is
        // a member of both; align the session context so the sidebar follows.
        if ($workspace->getMerchant()->getId()?->toRfc4122() !== $this->merchantContext->current()?->getId()?->toRfc4122()) {
            $this->merchantContext->remember($workspace->getMerchant());
        }

        return $workspace;
    }
}
