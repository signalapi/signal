<?php

namespace App\Controller\App;

use App\Entity\Workspace;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;
use App\Service\AiDiagnoser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/trends')]
#[IsGranted('ROLE_USER')]
class TrendController extends AbstractAppController
{
    private const WINDOW = 30;

    #[Route('', name: 'app_trends', methods: ['GET'])]
    public function index(Workspace $workspace, TestFlowRepository $flows, FlowRunRepository $runs): Response
    {
        $this->assertWorkspace($workspace);

        [$rows, $wsPassed, $wsFinished] = $this->buildRows($workspace, $flows, $runs);

        return $this->render('app/trend/index.html.twig', [
            'workspace' => $workspace,
            'rows' => $rows,
            'ws_pass_rate' => $wsFinished > 0 ? (int) round($wsPassed / $wsFinished * 100) : null,
            'ws_finished' => $wsFinished,
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
        TestFlowRepository $flows,
        FlowRunRepository $runs,
        AiDiagnoser $ai,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('diagnose-trends' . $workspace->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$ai->isConfigured()) {
            return new JsonResponse(['configured' => false]);
        }

        [$rows, $wsPassed, $wsFinished] = $this->buildRows($workspace, $flows, $runs);

        $evidence = [
            'window' => sprintf('last %d runs per test', self::WINDOW),
            'workspace' => [
                'passRate' => $wsFinished > 0 ? (int) round($wsPassed / $wsFinished * 100) : null,
                'finishedRuns' => $wsFinished,
            ],
            'tests' => [],
        ];
        foreach ($rows as $row) {
            $statuses = [];
            foreach ($row['timeline'] as $r) {
                if ('running' !== $r->getStatus()) {
                    $statuses[] = $r->getStatus();
                }
            }
            $flips = 0;
            for ($i = 1; $i < \count($statuses); ++$i) {
                if ($statuses[$i] !== $statuses[$i - 1]) {
                    ++$flips;
                }
            }
            $evidence['tests'][] = [
                'name' => $row['flow']->getName(),
                'finishedRuns' => \count($statuses),
                'passRate' => $row['passRate'],
                'statusFlips' => $flips,
                'avgMs' => $row['avgMs'],
                'lastStatus' => null !== $row['last'] ? $row['last']->getStatus() : null,
            ];
        }

        try {
            return new JsonResponse(['configured' => true, 'analysis' => $ai->summarizeTrends($evidence, $httpRequest->getLocale())]);
        } catch (\Throwable $e) {
            return new JsonResponse(['configured' => true, 'error' => $e->getMessage()], 502);
        }
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    private function buildRows(Workspace $workspace, TestFlowRepository $flows, FlowRunRepository $runs): array
    {
        $rows = [];
        $wsPassed = 0;
        $wsFinished = 0;
        foreach ($flows->findByWorkspace($workspace) as $flow) {
            $recent = $runs->recentForFlow($flow, self::WINDOW);
            $counts = ['passed' => 0, 'failed' => 0, 'error' => 0, 'cancelled' => 0, 'running' => 0];
            $durSum = 0;
            $durN = 0;
            foreach ($recent as $r) {
                $counts[$r->getStatus()] = ($counts[$r->getStatus()] ?? 0) + 1;
                $d = $r->getDurationMs();
                if (null !== $d) {
                    $durSum += $d;
                    ++$durN;
                }
            }
            $finished = $counts['passed'] + $counts['failed'] + $counts['error'] + $counts['cancelled'];
            $wsPassed += $counts['passed'];
            $wsFinished += $finished;

            $rows[] = [
                'flow' => $flow,
                'total' => $runs->countForFlow($flow),
                'timeline' => array_reverse($recent), // oldest -> newest
                'counts' => $counts,
                'passRate' => $finished > 0 ? (int) round($counts['passed'] / $finished * 100) : null,
                'avgMs' => $durN > 0 ? (int) round($durSum / $durN) : null,
                'last' => $recent[0] ?? null,
            ];
        }

        return [$rows, $wsPassed, $wsFinished];
    }
}
