<?php

namespace App\Service;

use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks Claude to explain WHY a run failed and propose a fix, from the same
 * evidence the diagnose_run MCP tool exposes — plus suite-level and trend-level
 * analysis over the same data.
 *
 * The key is resolved from the admin panel first (PlatformSettings, sealed at
 * rest) and falls back to the ANTHROPIC_API_KEY environment variable, so a
 * self-hosted install can still be configured entirely from .env. With neither
 * set, isConfigured() is false and the UI shows a "not connected" note.
 */
class AiDiagnoser
{
    private const API_BASE = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RunDiagnostics $diagnostics,
        private readonly PlatformSettings $settings,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $envApiKey = '',
        #[Autowire(env: 'ANTHROPIC_MODEL')] private readonly string $envModel = '',
        // Optional: point at an Anthropic-compatible gateway (LiteLLM, a proxy…).
        #[Autowire(env: 'ANTHROPIC_BASE_URL')] private readonly string $envBaseUrl = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey();
    }

    /** Where the active key comes from: 'panel', 'env' or null. */
    public function keySource(): ?string
    {
        if ('' !== trim((string) $this->settings->get(PlatformSettings::AI_API_KEY))) {
            return 'panel';
        }

        return '' !== trim($this->envApiKey) ? 'env' : null;
    }

    /** The model requests are sent with (panel setting > env > default). */
    public function activeModel(): string
    {
        $panel = trim((string) $this->settings->get(PlatformSettings::AI_MODEL));
        if ('' !== $panel) {
            return $panel;
        }

        return '' !== trim($this->envModel) ? trim($this->envModel) : self::DEFAULT_MODEL;
    }

    /**
     * A minimal round-trip to verify the key works. Throws on any failure.
     */
    public function ping(): void
    {
        $this->complete('Reply with the single word: ok', 'ping', 16);
    }

    /**
     * Returns Claude's diagnosis text, written in $locale. Throws if not
     * configured or on API error.
     */
    public function diagnose(FlowRun $run, string $locale = 'en'): string
    {
        $evidence = $this->diagnostics->evidence($run);

        $system = 'You are an API testing expert. You will be given the evidence of a failed test run as JSON '
            . '(requests, actual response bodies, failing assertions, error messages). Answer concisely with: '
            . '(1) a one-sentence SUMMARY, (2) the LIKELY ROOT CAUSE, (3) a SUGGESTED FIX (which step, what should '
            . 'change — distinguish a wrong assertion from a genuine bug or business-logic problem). '
            . 'Write in ' . $this->language($locale) . ', as short bullet points, without padding.';

        return $this->complete($system, "Failed run evidence:\n\n" . $this->json($evidence));
    }

    /**
     * Analyses a whole suite batch: groups failures by shared cause and says
     * whether they are new or recurring, using recent batch history.
     */
    public function diagnoseSuite(FlowGroupRun $groupRun, string $locale = 'en'): string
    {
        $evidence = $this->diagnostics->suiteEvidence($groupRun);

        $system = 'You are an API testing expert reviewing one batch run of a test suite. You get JSON evidence: '
            . 'per-flow statuses, full evidence for the failing runs (requests, response bodies, failing assertions) '
            . 'and the recent history of this suite\'s batches. Answer concisely with: '
            . '(1) a one-sentence SUMMARY of the batch, (2) ROOT CAUSES — group failures that share one cause '
            . 'instead of repeating it per flow, (3) NEW OR RECURRING — compare against recentBatches, '
            . '(4) a SUGGESTED FIX per cause (distinguish a wrong assertion from a genuine bug, an environment '
            . 'problem or a third-party outage). Write in ' . $this->language($locale) . ', as short bullet points.';

        return $this->complete($system, "Suite batch evidence:\n\n" . $this->json($evidence), 1536);
    }

    /**
     * Summarises workspace trends: what broke, what is flaky, what to do.
     *
     * @param array<string, mixed> $evidence
     */
    public function summarizeTrends(array $evidence, string $locale = 'en'): string
    {
        $system = 'You are an API testing expert reviewing a workspace\'s recent test trends as JSON '
            . '(per-test pass rates, status flips, durations over the recent window). Answer concisely with: '
            . '(1) a one-sentence OVERALL HEALTH verdict, (2) WHAT LOOKS BROKEN — worst pass rates that are '
            . 'consistently failing, (3) WHAT LOOKS FLAKY — frequent status flips, (4) RECOMMENDATIONS — the '
            . 'few actions with the highest impact. Ignore tests with too few runs to judge. '
            . 'Write in ' . $this->language($locale) . ', as short bullet points, without padding.';

        return $this->complete($system, "Trend evidence:\n\n" . $this->json($evidence), 1536);
    }

    /**
     * Raw completion for other AI features (flow generation etc.). Throws if
     * not configured or on API error.
     */
    public function completeText(string $system, string $user, int $maxTokens = 1024): string
    {
        return $this->complete($system, $user, $maxTokens);
    }

    private function complete(string $system, string $user, int $maxTokens = 1024): string
    {
        $apiKey = $this->apiKey();
        if ('' === $apiKey) {
            throw new \RuntimeException('AI analysis is not configured (no API key in the admin panel or ANTHROPIC_API_KEY).');
        }

        $base = '' !== trim($this->envBaseUrl) ? rtrim(trim($this->envBaseUrl), '/') : self::API_BASE;
        $response = $this->httpClient->request('POST', $base . '/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $this->activeModel(),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        if (isset($data['error'])) {
            throw new \RuntimeException('Anthropic API error: ' . ($data['error']['message'] ?? 'unknown'));
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return '' !== trim($text) ? $text : 'The model returned an empty response.';
    }

    private function apiKey(): string
    {
        $panel = trim((string) $this->settings->get(PlatformSettings::AI_API_KEY));

        return '' !== $panel ? $panel : trim($this->envApiKey);
    }

    private function language(string $locale): string
    {
        return 'tr' === $locale ? 'Turkish' : 'English';
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function json(array $evidence): string
    {
        return (string) json_encode($evidence, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
