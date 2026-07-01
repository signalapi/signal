<?php

namespace App\Service;

use App\Entity\FlowRun;

/**
 * Builds the raw evidence for diagnosing a failed run — shared by the MCP
 * diagnose_run tool and the in-panel "AI ile teşhis et" action, so both see
 * exactly the same picture.
 */
class RunDiagnostics
{
    private const BODY_LIMIT = 4000;

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
}
