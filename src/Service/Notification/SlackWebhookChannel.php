<?php

namespace App\Service\Notification;

use App\Entity\FlowRun;
use App\Entity\NotificationDestination;
use App\Service\SecretCipher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Slack incoming webhook. The message leads with the outcome and the first
 * broken step — the thing someone reading #alerts actually needs — and links
 * back to the full report.
 */
class SlackWebhookChannel implements ChannelInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretCipher $cipher,
    ) {
    }

    public function supports(string $destinationType): bool
    {
        return NotificationDestination::TYPE_SLACK === $destinationType;
    }

    public function send(NotificationDestination $destination, array $payload): int
    {
        $url = $this->cipher->decrypt($destination->getUrlEncrypted());
        if ('' === $url) {
            throw new \RuntimeException('The destination URL could not be decrypted (APP_SECRET_KEY changed?).');
        }

        $response = $this->httpClient->request('POST', $url, [
            'json' => $this->body($payload),
            'timeout' => 15,
        ]);
        $code = $response->getStatusCode();
        if ($code < 200 || $code > 299) {
            throw new \RuntimeException(sprintf('Slack returned HTTP %d: %s', $code, mb_substr($response->getContent(false), 0, 300)));
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    private function body(array $p): array
    {
        $passed = FlowRun::STATUS_PASSED === ($p['status'] ?? '');
        $icon = match ($p['status'] ?? '') {
            FlowRun::STATUS_PASSED => ':white_check_mark:',
            FlowRun::STATUS_CANCELLED => ':black_square_for_stop:',
            FlowRun::STATUS_ERROR => ':warning:',
            default => ':x:',
        };
        $kind = 'suite' === ($p['kind'] ?? '') ? 'Suite' : 'Test';
        $headline = sprintf('%s %s: %s', $icon, $kind, (string) ($p['title'] ?? '—'));

        $meta = array_filter([
            $p['workspace'] ?? null,
            $p['environment'] ?? null,
            sprintf('%d/%d %s passed', (int) ($p['passed'] ?? 0), (int) ($p['total'] ?? 0), (string) ($p['unit'] ?? 'step')),
            null === ($p['durationMs'] ?? null) ? null : sprintf('%.1fs', ((int) $p['durationMs']) / 1000),
            sprintf('trigger: %s', (string) ($p['trigger'] ?? 'manual')),
        ]);

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $this->escape($headline) . '*']],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $this->escape(implode('  ·  ', $meta))]]],
        ];

        if (!$passed && [] !== ($p['failures'] ?? [])) {
            $lines = [];
            foreach ((array) $p['failures'] as $failure) {
                $lines[] = sprintf("• *%s*\n   %s", $this->escape((string) ($failure['name'] ?? '')), $this->escape((string) ($failure['detail'] ?? '')));
            }
            if (($p['moreFailures'] ?? 0) > 0) {
                $lines[] = sprintf('• … and %d more', (int) $p['moreFailures']);
            }
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)]];
        }

        if (!empty($p['url'])) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open report'],
                    'url' => (string) $p['url'],
                ]],
            ];
        }

        return [
            // Fallback text for notifications and clients without Block Kit.
            'text' => $headline,
            'blocks' => $blocks,
        ];
    }

    /** Slack's mrkdwn needs only these three escaped. */
    private function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
