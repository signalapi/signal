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
        private readonly \Symfony\Component\Messenger\MessageBusInterface $bus,
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

        // Parallel suites fan out: one message per flow, several workers, and
        // the last member to finish finalises the batch (see the member handler).
        if ($group->isParallel()) {
            $groupRun = $this->groupRuns->findOneByBatch($message->batchId);
            $i = 0;
            foreach ($group->getFlows() as $flow) {
                if ($flow->getSteps()->isEmpty()) {
                    continue;
                }
                $this->bus->dispatch(new \App\Message\RunSuiteMemberMessage(
                    (string) $group->getId(),
                    $message->batchId,
                    (string) $flow->getId(),
                    $i,
                    $override ? (string) $override->getId() : null,
                    $message->triggeredByUserId,
                ));
                ++$i;
            }
            if (null !== $groupRun) {
                // The member handlers compare against this — count only what was
                // actually dispatched (flows without steps are skipped).
                $groupRun->setTotal($i);
                if (0 === $i) {
                    $groupRun->setStatus(FlowGroupRun::STATUS_PASSED);
                    $groupRun->setFinishedAt(new \DateTimeImmutable());
                }
                $this->groupRuns->save($groupRun);
                if (0 === $i) {
                    $this->events->dispatch(new SuiteRunFinished($groupRun, []));
                }
            }

            return;
        }

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
            // A quarantined (flaky) flow's failure is reported but does not turn
            // the batch red — checked after executeInto, because the run just
            // finished may itself have triggered quarantine or release.
            if (FlowRun::STATUS_PASSED !== $run->getStatus() && !$flow->isQuarantined()) {
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
