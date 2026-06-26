<?php

namespace App\Message;

/**
 * Dispatched to run a (pre-created) FlowRun in the background worker.
 */
final class RunFlowMessage
{
    /**
     * @param array<string, string> $vars
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $flowId,
        public readonly ?string $environmentId = null,
        public readonly array $vars = [],
    ) {
    }
}
