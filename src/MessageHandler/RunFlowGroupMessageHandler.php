<?php

namespace App\MessageHandler;

use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;
use App\Event\SuiteRunFinished;
use App\Message\RunFlowGroupMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowGroupRepository;
use App\Repository\FlowGroupRunRepository;
use App\Repository\UserRepository;
use App\Service\FlowRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsMessageHandler]
final class RunFlowGroupMessageHandler
{
    public function __construct(
        private readonly FlowGroupRepository $groups,
        private readonly FlowGroupRunRepository $groupRuns,
        private readonly EnvironmentRepository $environments,
        private readonly FlowRunner $runner,
        private readonly UserRepository $users,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(RunFlowGroupMessage $message): void
    {
        $actor = null !== $message->triggeredByUserId ? $this->users->find($message->triggeredByUserId) : null;

        $group = $this->groups->find($message->groupId);
        if (null === $group) {
            return;
        }

        $override = $message->environmentId ? $this->environments->find($message->environmentId) : null;

        $i = 0;
        $allPassed = true;
        $flowRuns = [];
        foreach ($group->getFlows() as $flow) {
            if ($flow->getSteps()->isEmpty()) {
                continue;
            }
            $env = $override ?? $flow->getDefaultEnvironment();
            // createRun tags each run with the shared batchId + its order (iteration);
            // executeInto runs it to completion before the loop moves to the next flow.
            $run = $this->runner->createRun($flow, $env, 'group', $message->batchId, $i, [], $actor);
            $this->runner->executeInto($run, $flow, $env);
            $flowRuns[] = $run;
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

            // The suite reports its own outcome once; the member flow runs stay
            // silent (see NotificationDispatcher).
            $this->events->dispatch(new SuiteRunFinished($groupRun, $flowRuns));
        }
    }
}
