<?php

namespace App\Event;

use App\Entity\EvaluationRun;

/**
 * Raised when an evaluation has been through every row and repeat. Carries the
 * EvaluationRun rather than the flow runs, because what anyone wants to hear
 * about an evaluation is the rate, not the individual results.
 */
final class EvaluationRunFinished
{
    public function __construct(public readonly EvaluationRun $run)
    {
    }
}
