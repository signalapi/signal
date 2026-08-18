<?php

namespace App\Service\Notification;

use App\Entity\NotificationDestination;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A way to put a run summary in front of a human (or another machine).
 * Implementations are auto-registered; NotificationSender picks the first one
 * that supports the destination's type.
 */
#[AutoconfigureTag('app.notification_channel')]
interface ChannelInterface
{
    public function supports(string $destinationType): bool;

    /**
     * Delivers the payload. Returns the HTTP status code; throws on transport
     * failure or a non-2xx response so Messenger can retry.
     *
     * @param array<string, mixed> $payload see RunSummary
     */
    public function send(NotificationDestination $destination, array $payload): int;
}
