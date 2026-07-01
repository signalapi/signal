<?php

namespace App\Controller;

use App\Repository\FlowRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, login-less read-only report for a shared run. Reached via a random
 * token link with an expiry; no workspace chrome, no controls.
 */
class PublicReportController extends AbstractController
{
    #[Route('/r/{token}', name: 'public_report', methods: ['GET'], requirements: ['token' => '[a-f0-9]{32}'])]
    public function report(string $token, FlowRunRepository $runs): Response
    {
        $run = $runs->findOneBy(['shareToken' => $token]);
        if (null === $run || !$run->isShareValid()) {
            // Expired or revoked → a plain, unstyled 404-ish page.
            return $this->render('public/report_gone.html.twig', [], new Response('', 404));
        }

        return $this->render('public/report.html.twig', ['run' => $run]);
    }
}
