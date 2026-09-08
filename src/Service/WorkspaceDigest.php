<?php

namespace App\Service;

use App\Entity\FlowRun;
use App\Entity\Workspace;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The scheduled workspace digest: pass rate over the recent window, what is
 * failing right now, what looks flaky, what sits in quarantine. Useful with
 * zero AI configured; when a key is present the sender enriches the message
 * with Claude's commentary at delivery time (payload.aiRequested).
 */
class WorkspaceDigest
{
    private const MAX_LIST = 8;

    public function __construct(
        private readonly TrendReport $trends,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed> a notification payload (kind: digest)
     */
    public function payload(Workspace $workspace): array
    {
        $report = $this->trends->build($workspace);

        $broken = [];
        $flaky = [];
        $quarantined = [];
        foreach ($report['rows'] as $row) {
            $name = $row['flow']->getName();
            if ($row['flow']->isQuarantined()) {
                $quarantined[] = $name;
            }
            $lastStatus = null !== $row['last'] ? $row['last']->getStatus() : null;
            if (\in_array($lastStatus, [FlowRun::STATUS_FAILED, FlowRun::STATUS_ERROR], true) && !$row['flow']->isQuarantined()) {
                $broken[] = $name;
            }
            if ($row['flips'] >= 3 && !$row['flow']->isQuarantined()) {
                $flaky[] = sprintf('%s (%d flips)', $name, $row['flips']);
            }
        }

        $passRate = $report['wsFinished'] > 0 ? (int) round($report['wsPassed'] / $report['wsFinished'] * 100) : null;

        return [
            'event' => 'digest',
            'kind' => 'digest',
            'status' => [] === $broken ? 'passed' : 'failed',
            'title' => $workspace->getName(),
            'workspace' => $workspace->getName(),
            'passRate' => $passRate,
            'finishedRuns' => $report['wsFinished'],
            'tests' => \count($report['rows']),
            'broken' => \array_slice($broken, 0, self::MAX_LIST),
            'moreBroken' => max(0, \count($broken) - self::MAX_LIST),
            'flaky' => \array_slice($flaky, 0, self::MAX_LIST),
            'quarantined' => \array_slice($quarantined, 0, self::MAX_LIST),
            'url' => $this->urls->generate('app_trends', ['workspace' => (string) $workspace->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            // The Claude commentary is fetched on the worker at send time, so a
            // slow AI call can never hold the scheduler tick open.
            'aiRequested' => true,
        ];
    }
}
