<?php

namespace App\MessageHandler;

use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;
use App\Message\RunFlowGroupMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowGroupRepository;
use App\Repository\FlowGroupRunRepository;
use App\Service\FlowRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunFlowGroupMessageHandler
{
    public function __construct(
        private readonly FlowGroupRepository $groups,
        private readonly FlowGroupRunRepository $groupRuns,
        private readonly EnvironmentRepository $environments,
        private readonly FlowRunner $runner,
    ) {
    }

    public function __invoke(RunFlowGroupMessage $message): void
    {
        $group = $this->groups->find($message->groupId);
        if (null === $group) {
            return;
        }

        $override = $message->environmentId ? $this->environments->find($message->environmentId) : null;

        $i = 0;
        $allPassed = true;
        foreach ($group->getFlows() as $flow) {
            if ($flow->getSteps()->isEmpty()) {
                continue;
            }
            $env = $override ?? $flow->getDefaultEnvironment();
            // createRun tags each run with the shared batchId + its order (iteration);
            // executeInto runs it to completion before the loop moves to the next flow.
            $run = $this->runner->createRun($flow, $env, 'group', $message->batchId, $i, []);
            $this->runner->executeInto($run, $flow, $env);
            if (FlowRun::STATUS_PASSED !== $run->getStatus()) {
                $allPassed = false;
            }
            ++$i;
        }

        // Mark the suite run finished with its outcome.
        $groupRun = $this->groupRuns->findOneByBatch($message->batchId);
        if (null !== $groupRun) {
            $groupRun->setStatus($allPassed ? FlowGroupRun::STATUS_PASSED : FlowGroupRun::STATUS_FAILED);
            $groupRun->setFinishedAt(new \DateTimeImmutable());
            $this->groupRuns->save($groupRun);
        }
    }
}
