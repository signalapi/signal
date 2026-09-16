<?php

namespace App\MessageHandler;

use App\Entity\EvaluationRun;
use App\Message\RunEvaluationMessage;
use App\Repository\EvaluationRunRepository;
use App\Repository\UserRepository;
use App\Service\EvaluationRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunEvaluationMessageHandler
{
    public function __construct(
        private readonly EvaluationRunRepository $runs,
        private readonly EvaluationRunner $runner,
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(RunEvaluationMessage $message): void
    {
        $run = $this->runs->find($message->evaluationRunId);
        if (null === $run || EvaluationRun::STATUS_RUNNING !== $run->getStatus()) {
            // Already finished (a retried message) — running it again would
            // double the spend and overwrite a result someone may be reading.
            return;
        }

        $actor = null !== $message->triggeredByUserId ? $this->users->find($message->triggeredByUserId) : null;

        $this->runner->execute($run, $message->baseVars, $actor);
    }
}
