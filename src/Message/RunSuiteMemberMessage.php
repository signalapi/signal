<?php

namespace App\Message;

/**
 * One flow of a PARALLEL suite batch. The batch is fanned out as one of these
 * per flow so several workers can run them concurrently; whoever finishes last
 * finalises the FlowGroupRun (see RunSuiteMemberMessageHandler).
 */
final class RunSuiteMemberMessage
{
    public function __construct(
        public readonly string $groupId,
        public readonly string $batchId,
        public readonly string $flowId,
        public readonly int $iteration,
        public readonly ?string $environmentId = null,
        public readonly ?string $triggeredByUserId = null,
    ) {
    }
}
