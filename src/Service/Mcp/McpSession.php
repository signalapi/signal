<?php

namespace App\Service\Mcp;

/**
 * One open conversation with an MCP server: where it is, what to send with every
 * request, and the session id the server handed back (spec-optional — Signal's
 * own server does not issue one, others do).
 *
 * Mutable on purpose: the id and the tool list are learned during the handshake
 * and then reused for the rest of the step.
 */
class McpSession
{
    /** @var array<int, array<string, mixed>>|null tools as the server declared them */
    public ?array $tools = null;

    public ?string $sessionId = null;

    public string $serverName = '';

    public string $protocolVersion = '';

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
        public readonly int $timeout = 30,
    ) {
    }
}
