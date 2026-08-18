<?php

namespace App\Event;

use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;

/**
 * Raised when every flow of a suite run has been executed.
 */
final class SuiteRunFinished
{
    /** @param FlowRun[] $runs the flow runs of this batch, in execution order */
    public function __construct(
        public readonly FlowGroupRun $groupRun,
        public readonly array $runs,
    ) {
    }
}
