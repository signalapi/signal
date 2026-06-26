<?php

namespace App\Controller\App;

use App\Entity\Workspace;
use App\Repository\FlowGroupRunRepository;
use App\Repository\FlowRunRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Unified run history: suite runs appear as a single suite entry, standalone flow
 * runs as individual entries — merged and sorted newest-first.
 */
#[Route('/app/workspaces/{workspace}/history')]
#[IsGranted('ROLE_MERCHANT')]
class HistoryController extends AbstractAppController
{
    #[Route('', name: 'app_history_index', methods: ['GET'])]
    public function index(Workspace $workspace, FlowRunRepository $runs, FlowGroupRunRepository $groupRuns): Response
    {
        $this->assertWorkspace($workspace);

        $items = [];

        // suite runs (one row each)
        foreach ($groupRuns->recentForWorkspace($workspace, 60) as $gr) {
            $passed = \count(array_filter($runs->findByBatch($gr->getBatchId()), static fn ($r) => 'passed' === $r->getStatus()));
            $items[] = [
                'kind' => 'suite',
                'ts' => $gr->getCreatedAt()->getTimestamp(),
                'createdAt' => $gr->getCreatedAt(),
                'name' => $gr->getFlowGroup()->getName(),
                'status' => $gr->getStatus(),
                'passed' => $passed,
                'total' => $gr->getTotal(),
                'url' => $this->generateUrl('app_flow_group_run_show', [
                    'workspace' => $workspace->getId(), 'group' => $gr->getFlowGroup()->getId(), 'batchId' => $gr->getBatchId(),
                ]),
            ];
        }

        // standalone flow runs (one row each)
        foreach ($runs->recentStandaloneForWorkspace($workspace, 100) as $run) {
            $items[] = [
                'kind' => 'flow',
                'ts' => $run->getCreatedAt()->getTimestamp(),
                'createdAt' => $run->getCreatedAt(),
                'run' => $run,
                'url' => $this->generateUrl('app_flow_run_show', [
                    'workspace' => $workspace->getId(), 'flow' => $run->getFlow()->getId(), 'run' => $run->getId(),
                ]),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
        $items = \array_slice($items, 0, 100);

        return $this->render('app/history/index.html.twig', [
            'workspace' => $workspace,
            'items' => $items,
        ]);
    }
}
