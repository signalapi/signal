<?php

namespace App\Service;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\Folder;
use App\Entity\ResponseExample;

/**
 * Turns a collection back into an OpenAPI 3 document — the inverse of
 * OpenApiImporter, so a team can shape an API in the workbench and publish it
 * to the marketplace.
 *
 * Round-trip matters: origin_key is written back as operationId, so re-importing
 * an exported spec matches the same operations instead of duplicating them.
 */
class OpenApiExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(ApiCollection $collection): array
    {
        $paths = [];
        $servers = [];

        foreach ($collection->getRequests() as $request) {
            if ($request->isDeprecated()) {
                continue;
            }

            [$server, $path] = $this->splitUrl($request->getUrl());
            if (null !== $server && !\in_array($server, $servers, true)) {
                $servers[] = $server;
            }
            if ('' === $path) {
                continue;
            }

            $method = strtolower($request->getMethod());
            $paths[$path] ??= [];
            // Two requests on the same method+path: keep the first, the spec
            // format has no room for a second.
            $paths[$path][$method] ??= $this->operation($request, $path);
        }

        if ([] === $paths) {
            throw new \InvalidArgumentException('The collection has no requests that can be expressed as endpoints.');
        }

        $doc = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $collection->getName(),
                'version' => '1.0.0',
            ],
            'paths' => $paths,
        ];
        if (null !== $collection->getDescription()) {
            $doc['info']['description'] = $collection->getDescription();
        }
        if ([] !== $servers) {
            $doc['servers'] = array_map(static fn (string $url) => ['url' => $url], $servers);
        }

        return $doc;
    }

    /**
     * Splits a request URL into an optional server prefix and a spec path.
     * A URL built on a variable ({{baseUrl}}/pets) contributes no server — the
     * importer will create an empty baseUrl variable for the consumer to fill.
     */
    /** @return array{0: ?string, 1: string} */
    private function splitUrl(string $url): array
    {
        $url = trim($url);
        // Drop a query string; query params live in the parameters list.
        $url = explode('?', $url, 2)[0];

        // {{baseUrl}}/x, {{host}}/x …
        if (preg_match('/^\{\{[^}]+\}\}(?<path>.*)$/', $url, $m)) {
            return [null, $this->pathTemplate($m['path'])];
        }

        $parts = parse_url($url);
        if (false !== $parts && isset($parts['scheme'], $parts['host'])) {
            $server = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

            return [$server, $this->pathTemplate($parts['path'] ?? '/')];
        }

        return [null, $this->pathTemplate($url)];
    }

    /** {{petId}} in a path becomes an OpenAPI {petId} template segment. */
    private function pathTemplate(string $path): string
    {
        $path = preg_replace('/\{\{([^}]+)\}\}/', '{$1}', $path) ?? $path;
        $path = '/' . ltrim($path, '/');

        return '/' === $path ? '/' : rtrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(ApiRequest $request, string $path): array
    {
        $op = [
            'summary' => $request->getName(),
            'responses' => $this->responses($request),
        ];

        // Reusing origin_key keeps identity stable across export → import.
        $operationId = $request->getOriginKey();
        if (null !== $operationId && 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $operationId)) {
            $op['operationId'] = $operationId;
        }

        $tag = $this->topFolderName($request);
        if ('' !== $tag) {
            $op['tags'] = [$tag];
        }

        $parameters = $this->pathParameters($path);
        foreach ($request->getQueryParams() as $pair) {
            $name = (string) ($pair['name'] ?? '');
            if ('' !== $name) {
                $parameters[] = $this->parameter($name, 'query', (string) ($pair['value'] ?? ''));
            }
        }
        foreach ($request->getHeaders() as $pair) {
            $name = (string) ($pair['name'] ?? '');
            // Content-Type is implied by the request body's media type.
            if ('' !== $name && 'content-type' !== strtolower($name)) {
                $parameters[] = $this->parameter($name, 'header', (string) ($pair['value'] ?? ''));
            }
        }
        if ([] !== $parameters) {
            $op['parameters'] = $parameters;
        }

        $body = $this->requestBody($request);
        if (null !== $body) {
            $op['requestBody'] = $body;
        }

        return $op;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $m);

        return array_map(
            static fn (string $name) => [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
            $m[1] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(string $name, string $in, string $value): array
    {
        $param = [
            'name' => $name,
            'in' => $in,
            'schema' => ['type' => 'string'],
        ];
        // A {{variable}} is the consumer's to fill in; anything else is a usable example.
        if ('' !== $value && !str_contains($value, '{{')) {
            $param['example'] = $value;
        }

        return $param;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(ApiRequest $request): ?array
    {
        $body = $request->getBody();
        if ('none' === $request->getBodyMode() || null === $body || '' === trim($body)) {
            return null;
        }

        if ('form' === $request->getBodyMode()) {
            parse_str($body, $form);
            $properties = [];
            foreach ($form as $key => $value) {
                $properties[(string) $key] = ['type' => 'string', 'example' => \is_scalar($value) ? (string) $value : ''];
            }

            return ['content' => ['application/x-www-form-urlencoded' => ['schema' => ['type' => 'object', 'properties' => $properties]]]];
        }

        $decoded = json_decode($body, true);
        if (\JSON_ERROR_NONE === json_last_error() && \is_array($decoded)) {
            return ['content' => ['application/json' => ['example' => $decoded]]];
        }

        return ['content' => ['text/plain' => ['example' => $body]]];
    }

    /**
     * Saved examples become documented responses; without any, a plain 200.
     *
     * @return array<string, mixed>
     */
    private function responses(ApiRequest $request): array
    {
        $responses = [];
        foreach ($request->getExamples() as $example) {
            /** @var ResponseExample $example */
            $code = (string) ($example->getStatusCode() ?? 200);
            if (isset($responses[$code])) {
                continue;
            }
            $entry = ['description' => $example->getName()];
            $body = $example->getResponseBody();
            if (null !== $body && '' !== trim($body)) {
                $decoded = json_decode($body, true);
                $entry['content'] = \JSON_ERROR_NONE === json_last_error() && \is_array($decoded)
                    ? ['application/json' => ['example' => $decoded]]
                    : ['text/plain' => ['example' => $body]];
            }
            $responses[$code] = $entry;
        }

        return [] === $responses ? ['200' => ['description' => 'OK']] : $responses;
    }

    private function topFolderName(ApiRequest $request): string
    {
        $folder = $request->getFolder();
        if (null === $folder) {
            return '';
        }
        while (null !== $folder->getParent()) {
            /** @var Folder $folder */
            $folder = $folder->getParent();
        }

        return $folder->getName();
    }
}
