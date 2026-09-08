<?php

namespace App\MessageHandler;

use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;
use App\Event\SuiteRunFinished;
use App\Message\RunSuiteMemberMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowGroupRepository;
use App\Repository\FlowGroupRunRepository;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;
use App\Repository\UserRepository;
use App\Service\FlowRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Runs ONE flow of a parallel suite batch. Finalisation is a race by design —
 * every member tries, but only the one that sees the whole batch finished (under
 * a row lock on the FlowGroupRun) flips the status and fires SuiteRunFinished;
 * everyone else observes "already finalised" or "not done yet" and walks away.
 */
#[AsMessageHandler]
final class RunSuiteMemberMessageHandler
{
    public function __construct(
        private readonly FlowGroupRepository $groups,
        private readonly TestFlowRepository $flows,
        private readonly FlowGroupRunRepository $groupRuns,
        private readonly FlowRunRepository $flowRuns,
        private readonly EnvironmentRepository $environments,
        private readonly UserRepository $users,
        private readonly FlowRunner $runner,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(RunSuiteMemberMessage $message): void
    {
        $group = $this->groups->find($message->groupId);
        $flow = $this->flows->find($message->flowId);
        if (null === $group || null === $flow
            || $flow->getWorkspace()->getId()?->toRfc4122() !== $group->getWorkspace()->getId()?->toRfc4122()) {
            return;
        }

        $environment = $message->environmentId ? $this->environments->find($message->environmentId) : $flow->getDefaultEnvironment();
        $actor = $message->triggeredByUserId ? $this->users->find($message->triggeredByUserId) : null;

        $run = $this->runner->createRun($flow, $environment, 'group', $message->batchId, $message->iteration, [], $actor);
        $this->runner->executeInto($run, $flow, $environment);

        $this->finalizeIfComplete($message->batchId);
    }

    private function finalizeIfComplete(string $batchId): void
    {
        $groupRun = $this->groupRuns->findOneByBatch($batchId);
        if (null === $groupRun || FlowGroupRun::STATUS_RUNNING !== $groupRun->getStatus()) {
            return;
        }

        $finalized = null;
        $this->em->wrapInTransaction(function () use (&$finalized, $groupRun, $batchId): void {
            // Re-read under a row lock: two members finishing together serialise
            // here, and the loser sees a status that is no longer "running".
            $locked = $this->em->find(FlowGroupRun::class, $groupRun->getId(), LockMode::PESSIMISTIC_WRITE);
            if (null === $locked || FlowGroupRun::STATUS_RUNNING !== $locked->getStatus()) {
                return;
            }

            $runs = $this->flowRuns->findByBatch($batchId);
            $done = array_filter($runs, static fn (FlowRun $r): bool => FlowRun::STATUS_RUNNING !== $r->getStatus());
            if (\count($done) < $locked->getTotal()) {
                return;
            }

            $allPassed = true;
            foreach ($runs as $r) {
                // Quarantined flaky flows do not turn the batch red (see the
                // sequential handler — same rule).
                if (FlowRun::STATUS_PASSED !== $r->getStatus() && !$r->getFlow()->isQuarantined()) {
                    $allPassed = false;
                    break;
                }
            }

            $locked->setStatus($allPassed ? FlowGroupRun::STATUS_PASSED : FlowGroupRun::STATUS_FAILED);
            $locked->setFinishedAt(new \DateTimeImmutable());
            $this->em->persist($locked);

            $finalized = ['groupRun' => $locked, 'runs' => $runs];
        });

        // Notifications and AI analysis fire outside the lock — a slow Slack
        // call must never hold a row lock on the batch.
        if (null !== $finalized) {
            $this->events->dispatch(new SuiteRunFinished($finalized['groupRun'], $finalized['runs']));
        }
    }
}
