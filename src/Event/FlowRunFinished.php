<?php

namespace App\Event;

use App\Entity\FlowRun;

/**
 * Raised once a flow run reaches a terminal status, from the single place every
 * caller goes through (FlowRunner::executeInto) — web, worker, API, MCP, cron.
 */
final class FlowRunFinished
{
    public function __construct(public readonly FlowRun $run)
    {
    }
}
