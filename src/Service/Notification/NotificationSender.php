<?php

namespace App\Service\Notification;

use App\Entity\NotificationDelivery;
use App\Entity\NotificationDestination;
use App\Repository\NotificationDeliveryRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Performs one delivery and records its outcome. Runs in the worker, never in
 * the request or run that produced the result.
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

        try {
            $code = $this->channel($destination)->send($destination, $delivery->getPayload());
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
