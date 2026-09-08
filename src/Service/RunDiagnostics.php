<?php

namespace App\Service;

use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;
use App\Repository\FlowGroupRunRepository;
use App\Repository\FlowRunRepository;

/**
 * Builds the raw evidence for diagnosing a failed run — shared by the MCP
 * diagnose_run tool and the in-panel "Diagnose with AI" action, so both see
 * exactly the same picture. suiteEvidence() widens the same idea to a whole
 * suite batch (per-flow statuses + failing runs + batch history).
 */
class RunDiagnostics
{
    private const BODY_LIMIT = 4000;

    /** Full evidence is heavy; cap how many failed runs a suite batch carries. */
    private const SUITE_FAILED_RUNS_LIMIT = 5;

    public function __construct(
        private readonly FlowRunRepository $runs,
        private readonly FlowGroupRunRepository $groupRuns,
    ) {
    }

    /**
     * @return array{
     *   run: array<string, mixed>,
     *   iterationData: array<string, mixed>,
     *   failingSteps: array<int, array<string, mixed>>
     * }
     */
    public function evidence(FlowRun $run): array
    {
        $failing = [];
        foreach ($run->getStepResults() as $r) {
            if (!\in_array($r->getStatus(), ['failed', 'error'], true)) {
                continue;
            }
            $failedAssertions = array_values(array_filter(
                $r->getAssertionResults(),
                static fn (array $a): bool => empty($a['ok']),
            ));
            $body = $r->getResponseBody();
            $failing[] = [
                'position' => $r->getPosition(),
                'label' => $r->getLabel(),
                'status' => $r->getStatus(),
                'attempts' => $r->getAttempts(),
                'method' => $r->getRequestMethod(),
                'target' => $r->getRequestUrl(),
                'responseStatus' => $r->getResponseStatus(),
                'durationMs' => $r->getDurationMs(),
                'responseBody' => null === $body ? null : mb_substr($body, 0, self::BODY_LIMIT),
                'failedAssertions' => $failedAssertions,
                'contractDrift' => $r->getShapeDrift(),
                'extracted' => $r->getExtractedVars(),
                'error' => $r->getError(),
            ];
        }
        usort($failing, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        // Drift can also appear on steps that still passed — surface it too, so a
        // failure can be correlated with a contract change upstream.
        $drift = [];
        foreach ($run->getStepResults() as $r) {
            if ([] !== $r->getShapeDrift()) {
                $drift[] = ['step' => $r->getLabel(), 'changes' => $r->getShapeDrift()];
            }
        }

        return [
            'run' => [
                'id' => (string) $run->getId(),
                'flow' => $run->getFlow()->getName(),
                'status' => $run->getStatus(),
                'environment' => $run->getEnvironmentName(),
                'passedSteps' => $run->getPassedSteps(),
                'totalSteps' => $run->getTotalSteps(),
            ],
            'iterationData' => $run->getIterationData(),
            'failingSteps' => $failing,
            'contractDrift' => $drift,
        ];
    }

    /**
     * Evidence for a whole suite batch: every flow's outcome, full evidence for
     * the first few failed runs, and the suite's recent batch history so the
     * analysis can tell a fresh regression from a long-standing red.
     *
     * @return array<string, mixed>
     */
    public function suiteEvidence(FlowGroupRun $groupRun): array
    {
        $flows = [];
        $failingRuns = [];
        $skippedFailures = 0;
        foreach ($this->runs->findByBatch($groupRun->getBatchId()) as $r) {
            $flows[] = [
                'flow' => $r->getFlow()->getName(),
                'status' => $r->getStatus(),
                'passedSteps' => $r->getPassedSteps(),
                'totalSteps' => $r->getTotalSteps(),
                'durationMs' => $r->getDurationMs(),
            ];
            if (\in_array($r->getStatus(), ['failed', 'error'], true)) {
                if (\count($failingRuns) < self::SUITE_FAILED_RUNS_LIMIT) {
                    $failingRuns[] = $this->evidence($r);
                } else {
                    ++$skippedFailures;
                }
            }
        }

        $history = [];
        foreach ($this->groupRuns->recentForGroup($groupRun->getFlowGroup(), 10) as $g) {
            $history[] = [
                'at' => $g->getCreatedAt()->format('Y-m-d H:i'),
                'status' => $g->getStatus(),
                'trigger' => $g->getTrigger(),
            ];
        }

        return [
            'suite' => [
                'name' => $groupRun->getFlowGroup()->getName(),
                'status' => $groupRun->getStatus(),
                'trigger' => $groupRun->getTrigger(),
                'totalFlows' => $groupRun->getTotal(),
                'environmentNote' => 'flows may target different environments; see each run',
            ],
            'flows' => $flows,
            'failingRuns' => $failingRuns,
            'failingRunsOmitted' => $skippedFailures,
            'recentBatches' => $history,
        ];
    }
}
