<?php

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRun;
use App\Entity\User;
use App\Event\EvaluationRunFinished;
use App\Repository\EvaluationRunRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Runs an Evaluation and keeps the result.
 *
 * The record is created before the first flow run, holding the batch id it is
 * about to produce, so a long evaluation is observable while it is happening
 * rather than appearing only once it is over. What it stores at the end is the
 * report, not the raw runs — the raw runs are already in flow_run, and the
 * point of an evaluation is the number you can put next to last week's.
 */
class EvaluationRunner
{
    public function __construct(
        private readonly FlowRunner $runner,
        private readonly EvalReport $report,
        private readonly EvaluationRunRepository $runs,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param array<string, mixed>|null $notifyOverride
     */
    public function createRun(Evaluation $evaluation, string $trigger = 'manual', ?array $notifyOverride = null): EvaluationRun
    {
        $run = new EvaluationRun();
        $run->setEvaluation($evaluation);
        $run->setBatchId(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $run->setTrigger($trigger);
        $run->setEnvironmentName($evaluation->getEnvironment()?->getName());
        $run->setNotifyOverride($notifyOverride);
        $run->setStatus(EvaluationRun::STATUS_RUNNING);
        $this->runs->save($run);

        return $run;
    }

    /**
     * Executes an already-created run to completion.
     *
     * @param array<string, string> $baseVars merged under every row (personal env values)
     */
    public function execute(EvaluationRun $run, array $baseVars = [], ?User $actor = null): EvaluationRun
    {
        $evaluation = $run->getEvaluation();

        try {
            $flowRuns = $this->runner->runDataset(
                $evaluation->getFlow(),
                $evaluation->getEnvironment(),
                $evaluation->getDataset(),
                'eval:' . $run->getTrigger(),
                $baseVars,
                $actor,
                $evaluation->getRepeats(),
                $run->getBatchId(),
                false,
            );
            $run->applyReport($this->report->of($flowRuns));
            $run->setStatus(EvaluationRun::STATUS_DONE);
        } catch (\Throwable $e) {
            // A broken evaluation is a result too — it must not look like a run
            // that simply scored zero, or the trend reads as a quality drop.
            $run->setStatus(EvaluationRun::STATUS_ERROR);
            $run->setError($e->getMessage());
        }

        $run->setFinishedAt(new \DateTimeImmutable());
        $this->runs->save($run);

        $this->events->dispatch(new EvaluationRunFinished($run));

        return $run;
    }
}
