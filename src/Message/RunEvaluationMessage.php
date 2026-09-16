<?php

namespace App\Message;

/**
 * Runs an evaluation in the background worker. A real eval is rows × repeats,
 * which outlives any HTTP request long before it is big enough to be useful,
 * so the run record is created up front and this only carries its id.
 */
final class RunEvaluationMessage
{
    public function __construct(
        public readonly string $evaluationRunId,
        /** Whose run this is; null for scheduled runs. */
        public readonly ?string $triggeredByUserId = null,
        /** @var array<string, string> personal environment values of the actor */
        public readonly array $baseVars = [],
    ) {
    }
}
