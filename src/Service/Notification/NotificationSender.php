<?php

namespace App\Service\Notification;

use App\Entity\FlowRun;
use App\Entity\NotificationDelivery;
use App\Entity\NotificationDestination;
use App\Repository\FlowGroupRunRepository;
use App\Repository\FlowRunRepository;
use App\Repository\NotificationDeliveryRepository;
use App\Service\AiDiagnoser;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Performs one delivery and records its outcome. Runs in the worker, never in
 * the request or run that produced the result.
 *
 * When a rule asked for it (payload.aiRequested) and the result is not a pass,
 * Claude's root-cause analysis is fetched here — on the async budget — and
 * folded into the payload before the channel renders it. The analysis is
 * cached back onto the delivery so a Messenger retry never pays for the same
 * Claude call twice, and any AI failure is swallowed: the notification itself
 * must always go out.
 */
class NotificationSender
{
    /**
     * @param iterable<ChannelInterface> $channels
     */
    public function __construct(
        #[AutowireIterator('app.notification_channel')]
        private readonly iterable $channels,
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly AiDiagnoser $ai,
        private readonly FlowRunRepository $runs,
        private readonly FlowGroupRunRepository $groupRuns,
    ) {
    }

    /**
     * @throws \Throwable so Messenger retries the delivery
     */
    public function deliver(NotificationDelivery $delivery): void
    {
        $destination = $delivery->getDestination();
        if (null === $destination) {
            $this->fail($delivery, 'The destination was deleted before the message could be sent.');

            return;
        }
        if (!$destination->isActive()) {
            $this->fail($delivery, 'The destination is paused.');

            return;
        }

        $delivery->setAttempts($delivery->getAttempts() + 1);
        $this->deliveries->save($delivery);

        $payload = $this->withAiAnalysis($delivery);

        try {
            $code = $this->channel($destination)->send($destination, $payload);
        } catch (\Throwable $e) {
            $this->fail($delivery, $e->getMessage());

            throw $e;
        }

        $delivery->setStatus(NotificationDelivery::STATUS_SENT);
        $delivery->setResponseCode($code);
        $delivery->setError(null);
        $delivery->setSentAt(new \DateTimeImmutable());
        $this->deliveries->save($delivery);
    }

    /**
     * @return array<string, mixed> the payload, with aiAnalysis added when possible
     */
    private function withAiAnalysis(NotificationDelivery $delivery): array
    {
        $payload = $delivery->getPayload();

        if (true !== ($payload['aiRequested'] ?? false) || isset($payload['aiAnalysis'])) {
            return $payload;
        }
        // A pass needs no root-cause analysis; datasets are per-row and skipped for now.
        if (FlowRun::STATUS_PASSED === ($payload['status'] ?? '') || !$this->ai->isConfigured()) {
            return $payload;
        }

        try {
            $ref = (string) ($payload['runId'] ?? '');
            $analysis = match ($payload['kind'] ?? '') {
                'flow' => Uuid::isValid($ref) && null !== ($run = $this->runs->find($ref))
                    ? $this->ai->diagnose($run) : null,
                'suite' => null !== ($groupRun = $this->groupRuns->findOneByBatch($ref))
                    ? $this->ai->diagnoseSuite($groupRun) : null,
                default => null,
            };
        } catch (\Throwable) {
            return $payload;
        }

        if (null !== $analysis && '' !== trim($analysis)) {
            $payload['aiAnalysis'] = $analysis;
            $delivery->setPayload($payload);
            $this->deliveries->save($delivery);
        }

        return $payload;
    }

    private function channel(NotificationDestination $destination): ChannelInterface
    {
        foreach ($this->channels as $channel) {
            if ($channel->supports($destination->getType())) {
                return $channel;
            }
        }

        throw new \RuntimeException(sprintf('No channel can handle destination type "%s".', $destination->getType()));
    }

    private function fail(NotificationDelivery $delivery, string $message): void
    {
        $delivery->setStatus(NotificationDelivery::STATUS_FAILED);
        $delivery->setError($message);
        $this->deliveries->save($delivery);
    }
}
