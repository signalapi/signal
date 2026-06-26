<?php

namespace App\Controller\Admin;

use App\Repository\MerchantRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('', name: 'admin_dashboard')]
    public function index(
        MerchantRepository $merchants,
        WorkspaceRepository $workspaces,
        UserRepository $users,
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'merchant_count' => $merchants->count([]),
            'workspace_count' => $workspaces->count([]),
            'user_count' => $users->count([]),
            'recent_merchants' => $merchants->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }
}
