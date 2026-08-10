<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    // ---- Merchant area (main firewall) ----

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }

    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('landing.html.twig');
    }

    // ---- Super admin area (admin firewall) ----

    #[Route('/admin/login', name: 'admin_login')]
    public function adminLogin(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('security/admin_login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/admin/logout', name: 'admin_logout')]
    public function adminLogout(): void
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }
}
