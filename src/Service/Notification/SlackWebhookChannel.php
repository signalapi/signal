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
     * Block Kit, but only with blocks a legacy incoming webhook can render:
     * section, context and divider. An actions block with a link button makes
     * Slack print "this app is using an older integration that doesn't support
     * this feature" next to the message, so the report link is a plain mrkdwn
     * link instead.
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    private function body(array $p): array
    {
        $icon = match ($p['status'] ?? '') {
            FlowRun::STATUS_PASSED => ':white_check_mark:',
            FlowRun::STATUS_CANCELLED => ':black_square_for_stop:',
            FlowRun::STATUS_ERROR => ':warning:',
            default => ':x:',
        };
        $kind = match ($p['kind'] ?? '') {
            'suite' => 'Suite',
            'dataset' => 'Data-driven test',
            default => 'Test',
        };
        $unit = (string) ($p['unit'] ?? 'step');
        $headline = sprintf(
            '%s %s: %s — %d/%d %s passed',
            $icon,
            $kind,
            (string) ($p['title'] ?? '—'),
            (int) ($p['passed'] ?? 0),
            (int) ($p['total'] ?? 0),
            $unit,
        );

        $meta = array_filter([
            $p['workspace'] ?? null,
            $p['environment'] ?? null,
            $this->duration($p['durationMs'] ?? null),
            sprintf('trigger: %s', (string) ($p['trigger'] ?? 'manual')),
        ]);

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $this->escape($headline) . '*']],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $this->escape(implode('  ·  ', $meta))]]],
        ];

        // What broke, with the assertion that caught it.
        if ([] !== ($p['failures'] ?? [])) {
            $lines = ['*What failed*'];
            foreach ((array) $p['failures'] as $failure) {
                $lines[] = sprintf(
                    "• *%s*\n   %s",
                    $this->escape((string) ($failure['name'] ?? '')),
                    $this->escape((string) ($failure['detail'] ?? '')),
                );
            }
            if (($p['moreFailures'] ?? 0) > 0) {
                $lines[] = sprintf('• … and %d more', (int) $p['moreFailures']);
            }
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->section($lines)]];
        }

        // The full rundown — suite flows, dataset rows or the run's own steps.
        if ([] !== ($p['items'] ?? [])) {
            $label = match ($p['kind'] ?? '') {
                'suite' => 'Flows',
                'dataset' => 'Rows',
                default => 'Steps',
            };
            $lines = ['*' . $label . '*'];
            foreach ((array) $p['items'] as $item) {
                // A suite line counts the flow's steps, not flows — the
                // run-level unit does not apply inside the rundown.
                $lines[] = $this->itemLine($item, 'step');
            }
            if (($p['moreItems'] ?? 0) > 0) {
                $lines[] = sprintf('_… and %d more_', (int) $p['moreItems']);
            }
            $blocks[] = ['type' => 'divider'];
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $this->section($lines)]];
        }

        if (!empty($p['url'])) {
            $blocks[] = ['type' => 'context', 'elements' => [[
                'type' => 'mrkdwn',
                'text' => sprintf('<%s|Open the full report>', (string) $p['url']),
            ]]];
        }

        return [
            // Fallback text for notifications and clients without Block Kit.
            'text' => $headline,
            'blocks' => $blocks,
        ];
    }

    /**
     * One rundown line: a status mark, the name, and whatever numbers that kind
     * of item has.
     *
     * @param array<string, mixed> $item
     */
    private function itemLine(array $item, string $unit): string
    {
        $status = (string) ($item['status'] ?? '');
        $mark = match ($status) {
            FlowRun::STATUS_PASSED => ':large_green_circle:',
            'skipped' => ':white_circle:',
            default => ':red_circle:',
        };

        $numbers = [];
        if (isset($item['passed'], $item['total'])) {
            $numbers[] = sprintf('%d/%d %s', (int) $item['passed'], (int) $item['total'], $unit);
        }
        if (null !== ($item['http'] ?? null)) {
            $numbers[] = 'HTTP ' . (int) $item['http'];
        }
        $duration = $this->duration($item['durationMs'] ?? null);
        if (null !== $duration) {
            $numbers[] = $duration;
        }

        return sprintf(
            '%s %s%s',
            $mark,
            $this->escape((string) ($item['name'] ?? '—')),
            [] === $numbers ? '' : '  `' . $this->escape(implode(' · ', $numbers)) . '`',
        );
    }

    /**
     * Joins lines into one mrkdwn text object, staying under Slack's 3000-char
     * limit per section.
     *
     * @param list<string> $lines
     */
    private function section(array $lines): string
    {
        $text = implode("\n", $lines);

        return mb_strlen($text) > 2900 ? mb_substr($text, 0, 2890) . "\n…" : $text;
    }

    private function duration(mixed $ms): ?string
    {
        if (null === $ms) {
            return null;
        }
        $ms = (int) $ms;

        return $ms < 1000 ? sprintf('%d ms', $ms) : sprintf('%.1fs', $ms / 1000);
    }

    /** Slack's mrkdwn needs only these three escaped. */
    private function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
