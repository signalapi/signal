<?php

namespace App\Service;

use App\Entity\FlowRun;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks Claude to explain WHY a run failed and propose a fix, from the same
 * evidence the diagnose_run MCP tool exposes.
 *
 * Wired but dormant by default: with no ANTHROPIC_API_KEY set, isConfigured()
 * is false and the UI shows a "not connected" note. Set the key (and
 * optionally ANTHROPIC_MODEL) to switch it on — no other code change needed.
 */
class AiDiagnoser
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RunDiagnostics $diagnostics,
        #[Autowire(env: 'ANTHROPIC_API_KEY')] private readonly string $apiKey = '',
        #[Autowire(env: 'ANTHROPIC_MODEL')] private readonly string $model = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * Returns Claude's diagnosis text. Throws if not configured or on API error.
     */
    public function diagnose(FlowRun $run): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AI teşhis yapılandırılmamış (ANTHROPIC_API_KEY yok).');
        }

        $evidence = $this->diagnostics->evidence($run);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => '' !== $this->model ? $this->model : 'claude-sonnet-4-6',
                'max_tokens' => 1024,
                'system' => $this->systemPrompt(),
                'messages' => [
                    ['role' => 'user', 'content' => $this->userPrompt($evidence)],
                ],
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        if (isset($data['error'])) {
            throw new \RuntimeException('Anthropic API hatası: ' . ($data['error']['message'] ?? 'bilinmiyor'));
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return '' !== trim($text) ? $text : 'Model boş yanıt döndü.';
    }

    private function systemPrompt(): string
    {
        return 'Sen bir API test uzmanısın. Sana başarısız bir test koşumunun kanıtı (istekler, gerçek yanıt gövdeleri, '
            . 'tutmayan assertion\'lar, hata mesajları) JSON olarak verilecek. Kısa ve net biçimde: (1) tek cümlelik ÖZET, '
            . '(2) OLASI KÖK SEBEP, (3) ÖNERİLEN DÜZELTME (hangi adım, ne değişmeli — assertion mı yanlış yoksa gerçekten bir '
            . 'bug/iş mantığı sorunu mu ayır). Türkçe, madde madde, gereksiz uzatma yok.';
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function userPrompt(array $evidence): string
    {
        return "Başarısız koşum kanıtı:\n\n" . (string) json_encode($evidence, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
