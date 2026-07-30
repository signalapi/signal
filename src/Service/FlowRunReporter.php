<?php

namespace App\Service;

use App\Entity\FlowRun;

/**
 * Serialises a FlowRun to JSON-friendly arrays and JUnit XML (for CI).
 */
class FlowRunReporter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(FlowRun $run): array
    {
        $steps = [];
        foreach ($run->getStepResults() as $r) {
            $steps[] = [
                'position' => $r->getPosition(),
                'label' => $r->getLabel(),
                'status' => $r->getStatus(),
                'attempts' => $r->getAttempts(),
                'method' => $r->getRequestMethod(),
                'target' => $r->getRequestUrl(),
                'responseStatus' => $r->getResponseStatus(),
                'durationMs' => $r->getDurationMs(),
                'extracted' => $r->getExtractedVars(),
                'assertions' => $r->getAssertionResults(),
                'contractDrift' => $r->getShapeDrift(),
                'error' => $r->getError(),
            ];
        }

        return [
            'id' => (string) $run->getId(),
            'flow' => $run->getFlow()->getName(),
            'status' => $run->getStatus(),
            'environment' => $run->getEnvironmentName(),
            'passedSteps' => $run->getPassedSteps(),
            'totalSteps' => $run->getTotalSteps(),
            'durationMs' => $run->getDurationMs(),
            'createdAt' => $run->getCreatedAt()->format(\DATE_ATOM),
            'steps' => $steps,
        ];
    }

    public function toJUnit(FlowRun $run): string
    {
        $failures = $run->getTotalSteps() - $run->getPassedSteps();
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $suite = $dom->createElement('testsuite');
        $suite->setAttribute('name', $run->getFlow()->getName());
        $suite->setAttribute('tests', (string) $run->getTotalSteps());
        $suite->setAttribute('failures', (string) max(0, $failures));
        $suite->setAttribute('time', (string) (($run->getDurationMs() ?? 0) / 1000));

        foreach ($run->getStepResults() as $r) {
            $case = $dom->createElement('testcase');
            $case->setAttribute('name', $r->getLabel());
            $case->setAttribute('classname', $run->getFlow()->getName());
            $case->setAttribute('time', (string) (($r->getDurationMs() ?? 0) / 1000));

            if (\in_array($r->getStatus(), ['failed', 'error'], true)) {
                $failed = [];
                foreach ($r->getAssertionResults() as $a) {
                    if (empty($a['ok'])) {
                        $failed[] = sprintf('%s (gelen: %s)', $a['label'] ?? '', $a['actual'] ?? '');
                    }
                }
                $message = $r->getError() ?? implode('; ', $failed) ?: 'assertion failed';
                $failure = $dom->createElement('failure', htmlspecialchars($message));
                $failure->setAttribute('message', $message);
                $case->appendChild($failure);
            } elseif ('skipped' === $r->getStatus()) {
                $case->appendChild($dom->createElement('skipped'));
            }

            $suite->appendChild($case);
        }

        $dom->appendChild($suite);

        return (string) $dom->saveXML();
    }
}
