<?php

namespace App\Service\Notification;

use App\Entity\FlowRun;
use App\Entity\NotificationDelivery;
use App\Entity\NotificationDestination;
use App\Entity\NotificationSubscription;
use App\Entity\Workspace;
use App\Event\DatasetRunFinished;
use App\Event\FlowRunFinished;
use App\Event\SuiteRunFinished;
use App\Message\SendNotificationMessage;
use App\Repository\NotificationDeliveryRepository;
use App\Repository\NotificationDestinationRepository;
use App\Repository\NotificationSubscriptionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Decides who hears about a finished run, then hands the actual sending to the
 * bus. Two sources feed the decision:
 *
 *  - standing rules (NotificationSubscription) for automated runs,
 *  - the choice made when the run was started (notifyOverride), which can add
 *    destinations or mute the run entirely.
 *
 * Interactive runs (someone pressed Run in the UI) stay quiet unless they were
 * explicitly marked, so a person debugging a flow does not spam a channel.
 */
class NotificationDispatcher
{
    /** Runs nobody is watching live: rules apply without being asked. */
    private const AUTOMATED_TRIGGERS = ['schedule', 'api', 'mcp'];

    public function __construct(
        private readonly NotificationSubscriptionRepository $subscriptions,
        private readonly NotificationDestinationRepository $destinations,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly RunSummary $summary,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener]
    public function onFlowRunFinished(FlowRunFinished $event): void
    {
        $run = $event->run;
        // Anything that runs as a batch — a suite, or one row of a dataset —
        // reports once at batch level instead of once per run.
        if (null !== $run->getBatchId()) {
            return;
        }
        if (FlowRun::STATUS_RUNNING === $run->getStatus()) {
            return;
        }

        $workspace = $run->getFlow()->getWorkspace();
        $targets = $this->resolve(
            $workspace,
            $run->getNotifyOverride(),
            NotificationSubscription::SCOPE_FLOW,
            $run->getFlow()->getId(),
            $run->getStatus(),
            \in_array($run->getTrigger(), self::AUTOMATED_TRIGGERS, true),
        );
        if ([] === $targets) {
            return;
        }

        $this->queue($workspace, $targets, $this->summary->fromFlowRun($run));
    }

    #[AsEventListener]
    public function onSuiteRunFinished(SuiteRunFinished $event): void
    {
        $groupRun = $event->groupRun;
        $group = $groupRun->getFlowGroup();
        $workspace = $group->getWorkspace();

        $targets = $this->resolve(
            $workspace,
            $groupRun->getNotifyOverride(),
            NotificationSubscription::SCOPE_SUITE,
            $group->getId(),
            $groupRun->getStatus(),
            \in_array($groupRun->getTrigger(), self::AUTOMATED_TRIGGERS, true),
        );
        if ([] === $targets) {
            return;
        }

        $this->queue($workspace, $targets, $this->summary->fromSuiteRun($groupRun, $event->runs));
    }

    #[AsEventListener]
    public function onDatasetRunFinished(DatasetRunFinished $event): void
    {
        if ([] === $event->runs) {
            return;
        }

        $first = $event->runs[0];
        $workspace = $event->flow->getWorkspace();
        $failed = array_filter($event->runs, static fn ($run) => FlowRun::STATUS_PASSED !== $run->getStatus());
        $status = [] === $failed ? FlowRun::STATUS_PASSED : FlowRun::STATUS_FAILED;

        $targets = $this->resolve(
            $workspace,
            $first->getNotifyOverride(),
            NotificationSubscription::SCOPE_FLOW,
            $event->flow->getId(),
            $status,
            \in_array($first->getTrigger(), self::AUTOMATED_TRIGGERS, true),
        );
        if ([] === $targets) {
            return;
        }

        $this->queue($workspace, $targets, $this->summary->fromDatasetBatch($event->flow, $event->batchId, $event->runs));
    }

    /**
     * Sends one payload to a destination right now (the "send a test message"
     * button), through the same queue and log as a real result.
     */
    public function queueTest(NotificationDestination $destination): void
    {
        $this->queue($destination->getWorkspace(), [$destination], $this->summary->testMessage($destination->getWorkspace()));
    }

    /**
     * The destinations that should hear about this outcome, de-duplicated: a
     * destination reached by both a rule and the run's own choice gets one
     * message, not two.
     *
     * @param array<string, mixed>|null $override
     *
     * @return NotificationDestination[]
     */
    private function resolve(
        Workspace $workspace,
        ?array $override,
        string $scopeType,
        ?Uuid $scopeId,
        string $status,
        bool $automated,
    ): array {
        if (null !== $override && true === ($override['mute'] ?? false)) {
            return [];   // "run this without telling anyone" wins over every rule
        }

        $targets = [];

        if ($automated) {
            foreach ($this->subscriptions->findMatching($workspace, $scopeType, $scopeId) as $subscription) {
                if ($subscription->wants($status)) {
                    $destination = $subscription->getDestination();
                    $targets[(string) $destination->getId()] = $destination;
                }
            }
        }

        $ids = array_values(array_filter(
            array_map('strval', (array) ($override['destinations'] ?? [])),
            static fn (string $id) => Uuid::isValid($id),
        ));
        if ([] !== $ids) {
            $condition = (string) ($override['condition'] ?? NotificationSubscription::WHEN_ALWAYS);
            $wanted = NotificationSubscription::WHEN_ALWAYS === $condition || FlowRun::STATUS_PASSED !== $status;
            if ($wanted) {
                // Re-read through the workspace so a stale or foreign id in the
                // override can never point at another workspace's channel.
                foreach ($this->destinations->findActiveByWorkspaceAndIds($workspace, $ids) as $destination) {
                    $targets[(string) $destination->getId()] = $destination;
                }
            }
        }

        return array_values($targets);
    }

    /**
     * @param NotificationDestination[] $targets
     * @param array<string, mixed>      $payload
     */
    private function queue(Workspace $workspace, array $targets, array $payload): void
    {
        foreach ($targets as $destination) {
            $delivery = new NotificationDelivery();
            $delivery->setWorkspace($workspace);
            $delivery->setDestination($destination);
            $delivery->setEvent((string) ($payload['event'] ?? NotificationDelivery::EVENT_FLOW_RUN));
            $delivery->setSubject(sprintf(
                '%s · %s',
                (string) ($payload['title'] ?? '—'),
                strtoupper((string) ($payload['status'] ?? '')),
            ));
            $delivery->setPayload($payload);
            $this->deliveries->save($delivery);

            $this->bus->dispatch(new SendNotificationMessage((string) $delivery->getId()));
        }
    }
}
