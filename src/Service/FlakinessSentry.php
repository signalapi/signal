<?php

namespace App\Service;

use App\Entity\FlowRun;
use App\Event\FlowRunFinished;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Watches every finished run and quarantines flows that flip status too often.
 * A quarantined flow keeps running, but its failures no longer turn a suite
 * batch red — they are reported separately, so one flaky test cannot bury the
 * suite's signal.
 *
 * Release is symmetric and automatic: once the recent tail is uniform the flow
 * comes back — including a uniformly FAILING tail, because a test that fails
 * every time is broken, not flaky, and must redden the suite again.
 */
class FlakinessSentry
{
    /** Finished runs examined when judging flakiness. */
    private const WINDOW = 10;

    /** Don't judge on a tiny sample. */
    private const MIN_SAMPLE = 6;

    /** This many status flips inside the window → quarantine. */
    private const FLIP_LIMIT = 3;

    /** This many uniform results in a row → release. */
    private const CALM_STREAK = 5;

    public function __construct(
        private readonly FlowRunRepository $runs,
        private readonly TestFlowRepository $flows,
    ) {
    }

    #[AsEventListener]
    public function onFlowRunFinished(FlowRunFinished $event): void
    {
        $run = $event->run;
        if (FlowRun::STATUS_RUNNING === $run->getStatus()) {
            return;
        }
        $flow = $run->getFlow();

        // Newest first; cancelled runs say nothing about flakiness.
        $statuses = [];
        foreach ($this->runs->recentForFlow($flow, self::WINDOW * 2) as $r) {
            if (\in_array($r->getStatus(), [FlowRun::STATUS_PASSED, FlowRun::STATUS_FAILED, FlowRun::STATUS_ERROR], true)) {
                $statuses[] = FlowRun::STATUS_PASSED === $r->getStatus() ? 'pass' : 'fail';
                if (\count($statuses) >= self::WINDOW) {
                    break;
                }
            }
        }

        if ($flow->isQuarantined()) {
            if (\count($statuses) >= self::CALM_STREAK
                && 1 === \count(array_unique(\array_slice($statuses, 0, self::CALM_STREAK)))) {
                $flow->setQuarantinedAt(null);
                $flow->setQuarantineNote(null);
                $this->flows->save($flow);
            }

            return;
        }

        if (\count($statuses) < self::MIN_SAMPLE) {
            return;
        }
        $flips = 0;
        for ($i = 1; $i < \count($statuses); ++$i) {
            if ($statuses[$i] !== $statuses[$i - 1]) {
                ++$flips;
            }
        }
        if ($flips >= self::FLIP_LIMIT) {
            $flow->setQuarantinedAt(new \DateTimeImmutable());
            $flow->setQuarantineNote(sprintf('%d status flips in the last %d runs', $flips, \count($statuses)));
            $this->flows->save($flow);
        }
    }
}
