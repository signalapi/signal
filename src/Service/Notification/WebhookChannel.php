<?php

namespace App\Service\Notification;

use App\Entity\NotificationDestination;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Plain HTTP POST of the run summary — n8n, Zapier, Make, an internal service.
 * Signed with HMAC-SHA256 over the exact body when the destination has a secret,
 * so the receiver can verify the call really came from Signal.
 */
class WebhookChannel implements ChannelInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretCipher $cipher,
    ) {
    }

    public function supports(string $destinationType): bool
    {
        return NotificationDestination::TYPE_WEBHOOK === $destinationType;
    }

    public function send(NotificationDestination $destination, array $payload): int
    {
        $url = $this->cipher->decrypt($destination->getUrlEncrypted());
        if ('' === $url) {
            throw new \RuntimeException('The destination URL could not be decrypted (APP_SECRET_KEY changed?).');
        }

        $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $body) {
            throw new \RuntimeException('The payload could not be encoded as JSON.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Signal-Notifier/1.0',
            'X-Signal-Event' => (string) ($payload['event'] ?? 'unknown'),
        ];

        $secret = null === $destination->getSecretEncrypted() ? '' : $this->cipher->decrypt($destination->getSecretEncrypted());
        if ('' !== $secret) {
            $headers['X-Signal-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 15,
        ]);
        $code = $response->getStatusCode();
        if ($code < 200 || $code > 299) {
            throw new \RuntimeException(sprintf('The endpoint returned HTTP %d: %s', $code, mb_substr($response->getContent(false), 0, 300)));
        }

        return $code;
    }
}
