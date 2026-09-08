<?php

namespace App\Service;

use App\Entity\FlowRun;
use App\Entity\Workspace;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;

/**
 * Per-flow trend rows over the recent window — one computation shared by the
 * Trends page, its AI summary and the scheduled workspace digest, so all three
 * always describe the same reality.
 */
class TrendReport
{
    public const WINDOW = 30;

    public function __construct(
        private readonly TestFlowRepository $flows,
        private readonly FlowRunRepository $runs,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, wsPassed: int, wsFinished: int}
     */
    public function build(Workspace $workspace): array
    {
        $rows = [];
        $wsPassed = 0;
        $wsFinished = 0;
        foreach ($this->flows->findByWorkspace($workspace) as $flow) {
            $recent = $this->runs->recentForFlow($flow, self::WINDOW);
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
                'total' => $this->runs->countForFlow($flow),
                'timeline' => array_reverse($recent), // oldest -> newest
                'counts' => $counts,
                'passRate' => $finished > 0 ? (int) round($counts['passed'] / $finished * 100) : null,
                'avgMs' => $durN > 0 ? (int) round($durSum / $durN) : null,
                'last' => $recent[0] ?? null,
                'flips' => $this->flips($recent),
            ];
        }

        return ['rows' => $rows, 'wsPassed' => $wsPassed, 'wsFinished' => $wsFinished];
    }

    /**
     * The compact JSON evidence the AI summary reads — no entities, no bodies.
     *
     * @param array{rows: list<array<string, mixed>>, wsPassed: int, wsFinished: int} $report
     *
     * @return array<string, mixed>
     */
    public function evidence(array $report): array
    {
        $evidence = [
            'window' => sprintf('last %d runs per test', self::WINDOW),
            'workspace' => [
                'passRate' => $report['wsFinished'] > 0 ? (int) round($report['wsPassed'] / $report['wsFinished'] * 100) : null,
                'finishedRuns' => $report['wsFinished'],
            ],
            'tests' => [],
        ];
        foreach ($report['rows'] as $row) {
            $evidence['tests'][] = [
                'name' => $row['flow']->getName(),
                'finishedRuns' => array_sum(array_intersect_key($row['counts'], array_flip(['passed', 'failed', 'error', 'cancelled']))),
                'passRate' => $row['passRate'],
                'statusFlips' => $row['flips'],
                'avgMs' => $row['avgMs'],
                'lastStatus' => null !== $row['last'] ? $row['last']->getStatus() : null,
                'quarantined' => $row['flow']->isQuarantined(),
            ];
        }

        return $evidence;
    }

    /**
     * @param FlowRun[] $recent newest first
     */
    private function flips(array $recent): int
    {
        $statuses = [];
        foreach ($recent as $r) {
            if (FlowRun::STATUS_RUNNING !== $r->getStatus()) {
                $statuses[] = $r->getStatus();
            }
        }
        $flips = 0;
        for ($i = 1; $i < \count($statuses); ++$i) {
            if ($statuses[$i] !== $statuses[$i - 1]) {
                ++$flips;
            }
        }

        return $flips;
    }
}
