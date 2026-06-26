<?php

namespace App\MessageHandler;

use App\Entity\FlowRun;
use App\Message\RunFlowMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;
use App\Service\FlowRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunFlowMessageHandler
{
    public function __construct(
        private readonly FlowRunRepository $runs,
        private readonly TestFlowRepository $flows,
        private readonly EnvironmentRepository $environments,
        private readonly FlowRunner $runner,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RunFlowMessage $message): void
    {
        $run = $this->runs->find($message->runId);
        if (null === $run || FlowRun::STATUS_RUNNING !== $run->getStatus()) {
            return; // already finished/cancelled, or gone
        }

        $flow = $this->flows->find($message->flowId);
        if (null === $flow) {
            $run->setStatus(FlowRun::STATUS_ERROR);
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();

            return;
        }

        $environment = $message->environmentId ? $this->environments->find($message->environmentId) : null;

        $this->runner->executeInto($run, $flow, $environment, $message->vars);
    }
}
