<?php

namespace App\Service\Mcp;

use App\Entity\Environment;
use App\Entity\McpServer;
use App\Repository\McpServerRepository;
use App\Service\VariableResolver;

/**
 * Keeps a server's tool catalogue up to date, and resolves a server's endpoint
 * for a run.
 *
 * Refreshing needs an environment, because the url and headers are written with
 * {{variables}} so one server row works across environments — which also means
 * "the catalogue" is really "the catalogue as seen through these credentials".
 * A server that fails to answer keeps its previous catalogue and records why:
 * losing a working list because the VPN dropped for a minute would take the
 * step editor down with it.
 */
class McpCatalog
{
    public function __construct(
        private readonly McpClient $client,
        private readonly McpServerRepository $servers,
        private readonly VariableResolver $resolver,
    ) {
    }

    /**
     * Re-reads the server's tools through the given environment's values.
     *
     * @return array{ok: bool, tools: int, error: ?string}
     */
    public function refresh(McpServer $server, ?Environment $environment): array
    {
        $context = null !== $environment ? $environment->toMap() : [];

        // Reaching a server whose endpoint is still {{mcpUrl}} fails deep in the
        // HTTP client with "scheme is missing", which says nothing about the
        // actual mistake. Name it before calling anything.
        $unresolved = $this->unresolved($server, $context);
        if ([] !== $unresolved) {
            $error = \sprintf(
                'Nothing supplies %s. Pick the environment that defines %s, or write the endpoint and headers without variables.',
                implode(', ', array_map(static fn (string $v): string => '{{' . $v . '}}', $unresolved)),
                1 === \count($unresolved) ? 'it' : 'them',
            );
            $server->setRefreshError($error);
            $this->servers->save($server);

            return ['ok' => false, 'tools' => \count($server->getTools()), 'error' => $error];
        }

        try {
            $session = $this->client->open(
                $this->resolveUrl($server, $context),
                $this->resolveHeaders($server, $context),
                30,
            );
        } catch (\Throwable $e) {
            $server->setRefreshError($e->getMessage());
            $this->servers->save($server);

            return ['ok' => false, 'tools' => \count($server->getTools()), 'error' => $e->getMessage()];
        }

        $server->setTools($session->tools ?? []);
        $server->setServerName($session->serverName ?: null);
        $server->setProtocolVersion($session->protocolVersion ?: null);
        $server->setToolsRefreshedAt(new \DateTimeImmutable());
        $server->setRefreshError(null);
        $this->servers->save($server);

        return ['ok' => true, 'tools' => \count($server->getTools()), 'error' => null];
    }

    /**
     * Variable names the endpoint or headers still need after resolving — the
     * difference between "the server is down" and "you have not told it which
     * environment to read as".
     *
     * @param array<string, string> $context
     *
     * @return string[]
     */
    private function unresolved(McpServer $server, array $context): array
    {
        $text = $this->resolveUrl($server, $context) . ' ' . implode(' ', $this->resolveHeaders($server, $context));
        preg_match_all('/\{\{\s*(\$?[\w.\-]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * @param array<string, string> $context
     */
    public function resolveUrl(McpServer $server, array $context): string
    {
        return (string) $this->resolver->resolve($server->getUrl(), $context);
    }

    /**
     * @param array<string, string> $context
     *
     * @return array<string, string>
     */
    public function resolveHeaders(McpServer $server, array $context): array
    {
        $out = [];
        foreach ($server->getHeaders() as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $out[strtolower($name)] = (string) $this->resolver->resolve((string) $value, $context);
            }
        }

        return $out;
    }
}
