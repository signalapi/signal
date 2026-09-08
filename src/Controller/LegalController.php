<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Privacy and terms — static, honest, and required: OAuth providers ask for a
 * privacy-policy URL before approving a client, and cloud users deserve to
 * know what is (and is not) done with their data.
 */
class LegalController extends AbstractController
{
    #[Route('/privacy', name: 'legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    #[Route('/terms', name: 'legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }
}
