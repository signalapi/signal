<?php

namespace App\Message;

/**
 * Dispatched per pending NotificationDelivery row. Sending lives on the bus so a
 * slow or broken Slack/webhook endpoint can never delay or fail a test run.
 */
final class SendNotificationMessage
{
    public function __construct(public readonly string $deliveryId)
    {
    }
}
