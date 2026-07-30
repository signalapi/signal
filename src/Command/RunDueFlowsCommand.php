<?php

namespace App\Command;

use App\Entity\TestFlow;
use App\Repository\TestFlowRepository;
use App\Service\FlowRunner;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:run-due-flows',
    description: 'Runs every scheduled flow whose cron expression is due. Invoke once per minute (system cron).',
)]
class RunDueFlowsCommand extends Command
{
    public function __construct(
        private readonly TestFlowRepository $flows,
        private readonly FlowRunner $runner,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $ran = 0;

        foreach ($this->flows->findScheduled() as $flow) {
            $expression = $flow->getCronExpression();
            if (null === $expression || !CronExpression::isValidExpression($expression)) {
                continue;
            }

            $cron = new CronExpression($expression);
            $due = $cron->getPreviousRunDate($now); // most recent scheduled moment at or before now
            $last = $flow->getLastScheduledRunAt();

            if (null !== $last && $last >= \DateTimeImmutable::createFromInterface($due)) {
                continue; // this occurrence already ran
            }

            $run = $this->runner->run($flow, $flow->getDefaultEnvironment(), 'schedule');
            $flow->setLastScheduledRunAt($now);
            $this->em->flush();
            ++$ran;

            $io->writeln(sprintf('- %s → %s (%d/%d)', $flow->getName(), strtoupper($run->getStatus()), $run->getPassedSteps(), $run->getTotalSteps()));
        }

        $io->success(sprintf('%d scheduled flow(s) executed.', $ran));

        return Command::SUCCESS;
    }
}
