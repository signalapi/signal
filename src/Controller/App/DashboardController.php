<?php

namespace App\Controller\App;

use App\Entity\User;
use App\Service\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app')]
#[IsGranted('ROLE_MERCHANT')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'app_dashboard')]
    public function index(WorkspaceContext $context): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (null === $user->getMerchant()) {
            throw $this->createAccessDeniedException('Hesabınız bir merchant ile ilişkili değil.');
        }

        // Open straight into the active workspace; if there are none yet, prompt to create one.
        $current = $context->current();
        if (null !== $current) {
            return $this->redirectToRoute('app_workspace_show', ['id' => $current->getId()]);
        }

        return $this->render('app/dashboard.html.twig', [
            'merchant' => $user->getMerchant(),
        ]);
    }
}
