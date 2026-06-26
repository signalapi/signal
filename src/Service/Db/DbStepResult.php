<?php

namespace App\Service\Db;

/**
 * Normalised result of running a database query for a flow step.
 */
class DbStepResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly mixed $data = null,
        public readonly string $display = '',
        public readonly float $durationMs = 0.0,
        public readonly ?string $error = null,
    ) {
    }
}
