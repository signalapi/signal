<?php

namespace App\Controller\App;

use App\Entity\Merchant;
use App\Entity\User;
use App\Entity\Workspace;
use App\Service\MerchantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Base controller for the merchant-facing app. Centralises tenant ownership checks
 * so a merchant can only ever touch its own data.
 */
abstract class AbstractAppController extends AbstractController
{
    protected MerchantContext $merchantContext;
    protected TranslatorInterface $translator;

    #[Required]
    public function setMerchantContext(MerchantContext $merchantContext): void
    {
        $this->merchantContext = $merchantContext;
    }

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
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
            throw $this->createAccessDeniedException($this->translator->trans('Your account is not associated with a merchant.'));
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
            $this->translator->trans('You do not have permission on this workspace.')
        );

        // A workspace of another merchant can be opened via URL when the user is
        // a member of both; align the session context so the sidebar follows.
        if ($workspace->getMerchant()->getId()?->toRfc4122() !== $this->merchantContext->current()?->getId()?->toRfc4122()) {
            $this->merchantContext->remember($workspace->getMerchant());
        }

        return $workspace;
    }
}
