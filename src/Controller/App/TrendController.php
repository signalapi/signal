<?php

namespace App\Controller\App;

use App\Entity\Workspace;
use App\Service\AiDiagnoser;
use App\Service\TrendReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/trends')]
#[IsGranted('ROLE_USER')]
class TrendController extends AbstractAppController
{
    #[Route('', name: 'app_trends', methods: ['GET'])]
    public function index(Workspace $workspace, TrendReport $trends): Response
    {
        $this->assertWorkspace($workspace);

        $report = $trends->build($workspace);

        return $this->render('app/trend/index.html.twig', [
            'workspace' => $workspace,
            'rows' => $report['rows'],
            'ws_pass_rate' => $report['wsFinished'] > 0 ? (int) round($report['wsPassed'] / $report['wsFinished'] * 100) : null,
            'ws_finished' => $report['wsFinished'],
        ]);
    }

    /**
     * Asks Claude for a health summary of the recent window: what is broken,
     * what is flaky, what to do first. Same JSON contract as the run/suite
     * diagnose actions: {configured:false} | {analysis} | {error}.
     */
    #[Route('/diagnose', name: 'app_trends_diagnose', methods: ['POST'])]
    public function diagnose(
        Workspace $workspace,
        Request $httpRequest,
        TrendReport $trends,
        AiDiagnoser $ai,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('diagnose-trends' . $workspace->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$ai->isConfigured()) {
            return new JsonResponse(['configured' => false]);
        }

        $evidence = $trends->evidence($trends->build($workspace));

        try {
            return new JsonResponse(['configured' => true, 'analysis' => $ai->summarizeTrends($evidence, $httpRequest->getLocale())]);
        } catch (\Throwable $e) {
            return new JsonResponse(['configured' => true, 'error' => $e->getMessage()], 502);
        }
    }
}
