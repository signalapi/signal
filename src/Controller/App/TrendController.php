<?php

namespace App\Controller\App;

use App\Entity\FlowRun;
use App\Entity\Workspace;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/trends')]
#[IsGranted('ROLE_MERCHANT')]
class TrendController extends AbstractAppController
{
    private const WINDOW = 30;

    #[Route('', name: 'app_trends', methods: ['GET'])]
    public function index(Workspace $workspace, TestFlowRepository $flows, FlowRunRepository $runs): Response
    {
        $this->assertWorkspace($workspace);

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

        return $this->render('app/trend/index.html.twig', [
            'workspace' => $workspace,
            'rows' => $rows,
            'ws_pass_rate' => $wsFinished > 0 ? (int) round($wsPassed / $wsFinished * 100) : null,
            'ws_finished' => $wsFinished,
        ]);
    }
}
