<?php

namespace App\MessageHandler;

use App\Entity\NotificationDelivery;
use App\Message\SendNotificationMessage;
use App\Repository\NotificationDeliveryRepository;
use App\Service\Notification\NotificationSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class SendNotificationMessageHandler
{
    public function __construct(
        private readonly NotificationDeliveryRepository $deliveries,
        private readonly NotificationSender $sender,
    ) {
    }

    public function __invoke(SendNotificationMessage $message): void
    {
        if (!Uuid::isValid($message->deliveryId)) {
            return;
        }

        $delivery = $this->deliveries->find(Uuid::fromString($message->deliveryId));
        if (null === $delivery || NotificationDelivery::STATUS_SENT === $delivery->getStatus()) {
            return; // gone, or already delivered by an earlier attempt
        }

        $this->sender->deliver($delivery);
    }
}
