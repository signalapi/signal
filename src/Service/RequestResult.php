<?php

namespace App\Service;

/**
 * Immutable result of executing an HTTP request.
 */
class RequestResult
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $statusCode = null,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $durationMs = 0.0,
        public readonly ?string $error = null,
    ) {
    }

    public function isJson(): bool
    {
        $contentType = strtolower(implode(' ', $this->headers['content-type'] ?? []));

        return str_contains($contentType, 'json');
    }

    public function prettyBody(): string
    {
        $body = (string) $this->body;
        if ($this->isJson()) {
            $decoded = json_decode($body, true);
            if (\JSON_ERROR_NONE === json_last_error()) {
                return json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
        }

        return $body;
    }
}
