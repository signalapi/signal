<?php

namespace App\Event;

use App\Entity\FlowRun;
use App\Entity\TestFlow;

/**
 * Raised when a data-driven run has been through every row. One event per batch,
 * not per row: a 50-row dataset is one result, not fifty.
 */
final class DatasetRunFinished
{
    /** @param FlowRun[] $runs one run per dataset row, in order */
    public function __construct(
        public readonly TestFlow $flow,
        public readonly string $batchId,
        public readonly array $runs,
    ) {
    }
}
