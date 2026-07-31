<?php

namespace App\Command;

use App\Entity\FlowGroupRun;
use App\Entity\Schedule;
use App\Message\RunFlowGroupMessage;
use App\Repository\FlowGroupRunRepository;
use App\Repository\ScheduleRepository;
use App\Service\FlowRunner;
use App\Service\ScheduleCompiler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One scheduler tick. Runs every schedule whose most recent due moment has not
 * been run yet.
 *
 * Replaces app:run-due-flows, which only knew about TestFlow::$cronExpression.
 *
 * Called once a minute — see the `scheduler` service in docker-compose.yml.
 * A flow runs inline (it is the same work the "run and wait" button does); a
 * suite is dispatched to the worker, because a suite is N flows back to back
 * and must not hold the tick open.
 */
#[AsCommand(
    name: 'app:run-due-schedules',
    description: 'Runs every schedule that is due. Invoke once per minute.',
)]
class RunDueSchedulesCommand extends Command
{
    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly ScheduleCompiler $compiler,
        private readonly FlowRunner $runner,
        private readonly FlowGroupRunRepository $groupRuns,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what is due without running it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable();
        $fired = 0;

        foreach ($this->schedules->findEnabled() as $schedule) {
            if (!$schedule->hasTarget()) {
                continue;
            }

            $due = $this->compiler->previousDue($schedule, $now);
            if (null === $due) {
                continue;   // no valid rule
            }

            $last = $schedule->getLastRunAt();
            if (null !== $last && $last >= $due) {
                continue;   // this occurrence already ran
            }

            if ($dryRun) {
                $io->writeln(sprintf('- [due %s] %s → %s', $due->format('Y-m-d H:i'), $schedule->getName(), $schedule->getTargetName()));
                ++$fired;
                continue;
            }

            $io->writeln(sprintf('- %s → %s', $schedule->getName(), $this->fire($schedule)));
            $schedule->setLastRunAt($now);
            $this->em->flush();
            ++$fired;
        }

        $io->success(sprintf('%d schedule(s) %s.', $fired, $dryRun ? 'due' : 'fired'));

        return Command::SUCCESS;
    }

    /** Runs the schedule's target and returns a one-line result for the log. */
    private function fire(Schedule $schedule): string
    {
        $group = $schedule->getFlowGroup();
        if (null !== $group) {
            if ($group->getFlows()->isEmpty()) {
                return 'suite is empty, skipped';
            }

            $batchId = Uuid::v4()->toRfc4122();
            $groupRun = new FlowGroupRun();
            $groupRun->setFlowGroup($group);
            $groupRun->setBatchId($batchId);
            $groupRun->setTotal($group->getFlows()->count());
            $this->groupRuns->save($groupRun);

            $this->bus->dispatch(new RunFlowGroupMessage(
                (string) $group->getId(),
                $batchId,
                $schedule->getEnvironment() ? (string) $schedule->getEnvironment()->getId() : null,
                null,   // nobody triggered it
            ));

            return sprintf('suite "%s" queued (%d flows)', $group->getName(), $group->getFlows()->count());
        }

        $flow = $schedule->getFlow();
        $run = $this->runner->run(
            $flow,
            $schedule->getEnvironment() ?? $flow->getDefaultEnvironment(),
            'schedule',
        );

        return sprintf('%s %s (%d/%d)', $flow->getName(), strtoupper($run->getStatus()), $run->getPassedSteps(), $run->getTotalSteps());
    }
}
