<?php

namespace App\Message;

/**
 * Dispatched to run all flows in a group sequentially in the background worker.
 * Each flow's FlowRun is tagged with the shared batchId so progress can be tracked.
 */
final class RunFlowGroupMessage
{
    public function __construct(
        public readonly string $groupId,
        public readonly string $batchId,
        public readonly ?string $environmentId = null,
    ) {
    }
}
