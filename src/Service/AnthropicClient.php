<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one place Signal talks to Claude — raw Messages API over Symfony's HTTP
 * client, because a self-hosted install may point ANTHROPIC_BASE_URL at a
 * gateway (LiteLLM, a corporate proxy) that an SDK client would not honour.
 *
 * The key is resolved from the admin panel first (PlatformSettings, sealed at
 * rest) and falls back to the ANTHROPIC_API_KEY environment variable, so a
 * self-hosted install can still be configured entirely from .env.
 *
 * Deliberately NOT sent: `temperature`. It was removed from the current model
 * generation (Opus 5, Sonnet 5, Opus 4.7+) and now returns a 400 — so pinning
 * a judge to temperature 0 would break the platform on its own default model.
 * Determinism comes from a tight rubric, not from a sampling knob.
 */
class AnthropicClient
{
    private const API_BASE = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-sonnet-4-6';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
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

    /** The reply text only — for callers that do not care what it cost. */
    public function text(string $system, string $user, int $maxTokens = 1024): string
    {
        return $this->complete($system, $user, $maxTokens)['text'];
    }

    /**
     * One Messages API round-trip.
     *
     * @return array{text: string, model: string, stopReason: string, inputTokens: int, outputTokens: int}
     */
    public function complete(string $system, string $user, int $maxTokens = 1024, ?string $model = null, int $timeout = 60): array
    {
        $apiKey = $this->apiKey();
        if ('' === $apiKey) {
            throw new \RuntimeException('AI analysis is not configured (no API key in the admin panel or ANTHROPIC_API_KEY).');
        }

        $model = null !== $model && '' !== trim($model) ? trim($model) : $this->activeModel();
        $base = '' !== trim($this->envBaseUrl) ? rtrim(trim($this->envBaseUrl), '/') : self::API_BASE;

        $response = $this->httpClient->request('POST', $base . '/v1/messages', [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ],
            'json' => array_filter([
                'model' => $model,
                'max_tokens' => $maxTokens,
                // Omitted rather than sent empty: a step may have no system prompt.
                'system' => '' !== trim($system) ? $system : null,
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ], static fn ($v) => null !== $v),
            'timeout' => $timeout,
        ]);

        $data = $response->toArray(false);
        if (isset($data['error'])) {
            throw new \RuntimeException('Anthropic API error: ' . ($data['error']['message'] ?? 'unknown'));
        }

        $stopReason = (string) ($data['stop_reason'] ?? '');
        // A refusal comes back as HTTP 200 with empty content — surface it as an
        // error rather than letting it read as "the model said nothing".
        if ('refusal' === $stopReason) {
            throw new \RuntimeException('The model declined to answer (stop_reason: refusal'
                . (isset($data['stop_details']['category']) ? ', category: ' . $data['stop_details']['category'] : '') . ').');
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return [
            'text' => '' !== trim($text) ? $text : 'The model returned an empty response.',
            'model' => (string) ($data['model'] ?? $model),
            'stopReason' => $stopReason,
            'inputTokens' => (int) ($data['usage']['input_tokens'] ?? 0),
            'outputTokens' => (int) ($data['usage']['output_tokens'] ?? 0),
        ];
    }

    /**
     * One turn of a tool-use conversation. Unlike complete(), the caller owns the
     * message history, because an agent loop must hand the assistant's content
     * back VERBATIM on the next turn — thinking blocks and tool_use blocks
     * included. Echoing only the text silently breaks the loop on models where
     * reasoning is bound to the turn that produced it.
     *
     * @param array<int, array<string, mixed>> $messages the conversation so far
     * @param array<int, array<string, mixed>> $tools    Anthropic tool definitions
     *
     * @return array<string, mixed> the raw Messages API response
     */
    public function converse(array $messages, array $tools, string $system = '', int $maxTokens = 2048, ?string $model = null, int $timeout = 120): array
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
            'json' => array_filter([
                'model' => null !== $model && '' !== trim($model) ? trim($model) : $this->activeModel(),
                'max_tokens' => $maxTokens,
                'system' => '' !== trim($system) ? $system : null,
                'messages' => $messages,
                'tools' => [] !== $tools ? $tools : null,
            ], static fn ($v) => null !== $v),
            'timeout' => $timeout,
        ]);

        $data = $response->toArray(false);
        if (isset($data['error'])) {
            throw new \RuntimeException('Anthropic API error: ' . ($data['error']['message'] ?? 'unknown'));
        }
        if (($data['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException('The model declined to answer (stop_reason: refusal'
                . (isset($data['stop_details']['category']) ? ', category: ' . $data['stop_details']['category'] : '') . ').');
        }

        return $data;
    }

    /**
     * Decodes a reply that was asked for as one JSON object, tolerating a
     * markdown fence the model may have wrapped it in anyway.
     *
     * @return array<string, mixed>|null null when the reply was not JSON
     */
    public function decodeJson(string $text): ?array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/', '', $text));
        }
        $decoded = json_decode($text, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private function apiKey(): string
    {
        $panel = trim((string) $this->settings->get(PlatformSettings::AI_API_KEY));

        return '' !== $panel ? $panel : trim($this->envApiKey);
    }
}
