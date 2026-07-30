<?php

namespace App\Controller\App;

use App\Service\WorkspaceContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractAppController
{
    #[Route('', name: 'app_dashboard')]
    public function index(WorkspaceContext $context): Response
    {
        $merchant = $this->currentMerchant();

        // Open straight into the active workspace; if there are none yet, prompt to create one.
        $current = $context->current();
        if (null !== $current) {
            return $this->redirectToRoute('app_workspace_show', ['id' => $current->getId()]);
        }

        return $this->render('app/dashboard.html.twig', [
            'merchant' => $merchant,
        ]);
    }
}
