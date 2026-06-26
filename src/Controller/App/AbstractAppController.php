<?php

namespace App\Controller\App;

use App\Entity\Merchant;
use App\Entity\User;
use App\Entity\Workspace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Base controller for the merchant-facing app. Centralises tenant ownership checks
 * so a merchant can only ever touch its own data.
 */
abstract class AbstractAppController extends AbstractController
{
    protected function currentMerchant(): Merchant
    {
        /** @var User $user */
        $user = $this->getUser();
        $merchant = $user->getMerchant();

        if (null === $merchant) {
            throw $this->createAccessDeniedException('Hesabınız bir merchant ile ilişkili değil.');
        }

        return $merchant;
    }

    protected function assertWorkspace(Workspace $workspace): Workspace
    {
        if ($workspace->getMerchant()->getId()?->toRfc4122() !== $this->currentMerchant()->getId()?->toRfc4122()) {
            throw $this->createAccessDeniedException('Bu workspace size ait değil.');
        }

        return $workspace;
    }
}
