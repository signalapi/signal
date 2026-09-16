<?php

namespace App\Service\Mcp;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A minimal MCP client — the other side of the server Signal already exposes in
 * McpController, so a flow can call an MCP server the way Claude calls Signal.
 *
 * Streamable HTTP with JSON-RPC 2.0. Only what testing needs: the initialize
 * handshake, tools/list and tools/call. No resources, no prompts, no sampling,
 * no server-initiated requests — a test client speaks, it does not listen.
 *
 * Two bits of tolerance, because servers in the wild differ from Signal's own:
 * a session id header is honoured when the server issues one, and a reply is
 * accepted as either plain JSON or an SSE stream (the transport allows both, so
 * a client that assumes JSON breaks against half of them).
 */
class McpClient
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SESSION_HEADER = 'mcp-session-id';

    private int $nextId = 1;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * Runs the handshake and returns a session with the server's tool list on it.
     *
     * @param array<string, string> $headers
     */
    public function open(string $url, array $headers = [], int $timeout = 30): McpSession
    {
        $session = new McpSession($url, $headers, $timeout);

        $init = $this->rpc($session, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'signal', 'version' => '1.0.0'],
        ]);
        $session->serverName = (string) ($init['serverInfo']['name'] ?? 'unknown');
        $session->protocolVersion = (string) ($init['protocolVersion'] ?? '');

        // Required by the spec; the server answers nothing, and a server that
        // rejects it should not cost us the step.
        try {
            $this->notify($session, 'notifications/initialized');
        } catch (\Throwable) {
        }

        $session->tools = $this->listTools($session);

        return $session;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTools(McpSession $session): array
    {
        $result = $this->rpc($session, 'tools/list', []);
        $tools = $result['tools'] ?? [];

        return \is_array($tools) ? array_values($tools) : [];
    }

    /**
     * Calls one tool.
     *
     * An MCP tool reports its OWN failures inside the result (isError), not as a
     * JSON-RPC error, so a failed tool call is a normal return here — the step's
     * assertions decide whether that counts as a test failure.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{isError: bool, text: string, result: mixed}
     */
    public function callTool(McpSession $session, string $name, array $arguments): array
    {
        $raw = $this->rpc($session, 'tools/call', [
            'name' => $name,
            // An empty PHP array encodes as [], and MCP wants an object here.
            'arguments' => [] === $arguments ? new \stdClass() : $arguments,
        ]);

        $text = '';
        foreach ((array) ($raw['content'] ?? []) as $block) {
            if (\is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        // structuredContent when the server provides it (Signal's does), else the
        // text content parsed as JSON, else the text as-is — so assertions can
        // address fields rather than matching prose.
        $result = $raw['structuredContent'] ?? null;
        if (null === $result) {
            $decoded = json_decode($text, true);
            $result = null !== $decoded ? $decoded : $text;
        }

        return [
            'isError' => (bool) ($raw['isError'] ?? false),
            'text' => $text,
            'result' => $result,
        ];
    }

    /**
     * Sends one JSON-RPC request and returns its `result`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(McpSession $session, string $method, array $params): array
    {
        $id = $this->nextId++;
        // An empty PHP array encodes as [], and `params` is an object position —
        // a tolerant server shrugs, a strict one rejects the whole request. The
        // spec lets params be omitted, so omit it rather than send the wrong type.
        $body = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ([] !== $params) {
            $body['params'] = $params;
        }

        $response = $this->httpClient->request('POST', $session->url, [
            'headers' => $this->headers($session),
            'json' => $body,
            'timeout' => $session->timeout,
        ]);

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);

        // Some servers mint the session on the initialize response.
        foreach ($response->getHeaders(false) as $name => $values) {
            if (self::SESSION_HEADER === strtolower($name) && isset($values[0])) {
                $session->sessionId = (string) $values[0];
            }
        }

        if ($status >= 400) {
            throw new \RuntimeException(sprintf('MCP server returned HTTP %d: %s', $status, mb_substr(trim($raw), 0, 300)));
        }

        $payload = $this->decode($raw, (string) ($response->getHeaders(false)['content-type'][0] ?? ''), $id);
        if (null === $payload) {
            throw new \RuntimeException('MCP server did not return a JSON-RPC response: ' . mb_substr(trim($raw), 0, 300));
        }
        if (isset($payload['error'])) {
            throw new \RuntimeException(sprintf(
                'MCP error %s: %s',
                (string) ($payload['error']['code'] ?? '?'),
                (string) ($payload['error']['message'] ?? 'unknown'),
            ));
        }

        $result = $payload['result'] ?? null;

        return \is_array($result) ? $result : [];
    }

    /** Fire-and-forget: a notification carries no id and expects no response. */
    private function notify(McpSession $session, string $method): void
    {
        $this->httpClient->request('POST', $session->url, [
            'headers' => $this->headers($session),
            'json' => ['jsonrpc' => '2.0', 'method' => $method, 'params' => new \stdClass()],
            'timeout' => $session->timeout,
        ])->getStatusCode();
    }

    /**
     * @return array<string, string>
     */
    private function headers(McpSession $session): array
    {
        $headers = [
            'content-type' => 'application/json',
            // The transport lets the server answer either way; say we take both.
            'accept' => 'application/json, text/event-stream',
        ] + $session->headers;

        if (null !== $session->sessionId) {
            $headers['mcp-session-id'] = $session->sessionId;
        }

        return $headers;
    }

    /**
     * Plain JSON, or the SSE frame answering request $id.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $raw, string $contentType, int $id): ?array
    {
        if (!str_contains(strtolower($contentType), 'text/event-stream')) {
            $decoded = json_decode($raw, true);

            return \is_array($decoded) ? $decoded : null;
        }

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $decoded = json_decode(trim(substr($line, 5)), true);
            // Must carry OUR id. A stream may emit notifications
            // (notifications/progress, notifications/message) before the answer,
            // and those are JSON-RPC too — returning the first one would hand
            // the caller an empty result that reads just like a successful call.
            if (\is_array($decoded) && isset($decoded['jsonrpc']) && ($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
        }

        return null;
    }
}
