<?php

namespace App\Service;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\Environment;
use App\Entity\EnvVariable;
use App\Entity\Folder;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Imports an OpenAPI 3.x (or Swagger 2.0) spec into our model: one folder per
 * tag, one ApiRequest per operation, URLs against a {{baseUrl}} variable, and
 * security schemes as secret {{...}} placeholders. Every request carries
 * origin_key/origin_hash so a later spec version can be three-way diffed.
 */
class OpenApiImporter
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
    private const SAMPLE_DEPTH = 8;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $data */
    public static function supports(array $data): bool
    {
        return isset($data['openapi']) || isset($data['swagger']);
    }

    /**
     * @param array<string, mixed> $data Decoded OpenAPI document
     */
    public function importCollection(array $data, Workspace $workspace): ApiCollection
    {
        $items = $this->buildRequests($data);

        $collection = new ApiCollection();
        $collection->setWorkspace($workspace);
        $collection->setName((string) ($data['info']['title'] ?? 'İçe aktarılan API'));
        $collection->setDescription($this->stringOrNull($data['info']['description'] ?? null));
        $collection->setSourceType('openapi');
        $this->em->persist($collection);

        /** @var array<string, Folder> $folders */
        $folders = [];
        $folderPos = 0;
        $rootPos = 0;
        $positions = [];

        foreach ($items as $item) {
            $tag = $item['tag'];
            $folder = null;
            if ('' !== $tag) {
                if (!isset($folders[$tag])) {
                    $folder = new Folder();
                    $folder->setCollection($collection);
                    $folder->setName($tag);
                    $folder->setPosition($folderPos++);
                    $this->em->persist($folder);
                    $folders[$tag] = $folder;
                    $positions[$tag] = 0;
                }
                $folder = $folders[$tag];
            }

            $request = $item['request'];
            $request->setCollection($collection);
            $request->setFolder($folder);
            $request->setPosition('' === $tag ? $rootPos++ : $positions[$tag]++);
            $this->em->persist($request);
        }

        $this->em->flush();

        return $collection;
    }

    /**
     * Builds unmanaged ApiRequest objects (with provenance filled) from a spec,
     * each paired with its tag. Shared by the real import and the update
     * planner, which diffs these against a collection without persisting.
     *
     * @param array<string, mixed> $data Decoded OpenAPI document
     *
     * @return list<array{tag: string, request: ApiRequest}>
     */
    public function buildRequests(array $data): array
    {
        $paths = $data['paths'] ?? null;
        if (!\is_array($paths) || [] === $paths) {
            throw new \InvalidArgumentException('Geçerli bir OpenAPI dokümanı değil ("paths" alanı yok ya da boş).');
        }

        $securitySchemes = (array) ($data['components']['securitySchemes'] ?? $data['securityDefinitions'] ?? []);
        $globalSecurity = (array) ($data['security'] ?? []);

        $items = [];
        foreach ($paths as $path => $operations) {
            if (!\is_array($operations)) {
                continue;
            }
            $pathParameters = (array) ($operations['parameters'] ?? []);

            foreach (self::METHODS as $method) {
                $op = $operations[$method] ?? null;
                if (!\is_array($op)) {
                    continue;
                }

                $request = $this->buildRequest(
                    (string) $path,
                    $method,
                    $op,
                    $pathParameters,
                    $securitySchemes,
                    $globalSecurity,
                    $data,
                );
                $request->setOriginHash(RequestProvenance::hash($request));
                $items[] = ['tag' => (string) ($op['tags'][0] ?? ''), 'request' => $request];
            }
        }

        return $items;
    }

    /**
     * Environment scaffold from the spec: {{baseUrl}} + one secret placeholder
     * per security scheme. Returns null when there is nothing to put in it.
     *
     * @param array<string, mixed> $data
     */
    public function importEnvironment(array $data, Workspace $workspace): ?Environment
    {
        $env = new Environment();
        $env->setWorkspace($workspace);
        $env->setName((string) ($data['info']['title'] ?? 'API') . ' (spec)');

        $baseUrl = new EnvVariable();
        $baseUrl->setName('baseUrl');
        $baseUrl->setValue($this->resolveServerUrl($data));
        $env->addVariable($baseUrl);

        foreach ((array) ($data['components']['securitySchemes'] ?? $data['securityDefinitions'] ?? []) as $scheme) {
            if (!\is_array($scheme)) {
                continue;
            }
            $name = $this->securityVariable($scheme);
            if (null === $name) {
                continue;
            }
            $variable = new EnvVariable();
            $variable->setName($name);
            $variable->setValue(null);
            $variable->setSecret(true);
            $env->addVariable($variable);
        }

        $this->em->persist($env);
        $this->em->flush();

        return $env;
    }

    /**
     * @param array<string, mixed> $op
     * @param list<mixed>          $pathParameters
     * @param array<string, mixed> $securitySchemes
     * @param list<mixed>          $globalSecurity
     * @param array<string, mixed> $doc
     */
    private function buildRequest(
        string $path,
        string $method,
        array $op,
        array $pathParameters,
        array $securitySchemes,
        array $globalSecurity,
        array $doc,
    ): ApiRequest {
        $request = new ApiRequest();
        $request->setName($this->stringOrNull($op['summary'] ?? null) ?? $this->stringOrNull($op['operationId'] ?? null) ?? strtoupper($method) . ' ' . $path);
        $request->setMethod(strtoupper($method));
        $request->setOriginKey(RequestProvenance::key($method, $path, $this->stringOrNull($op['operationId'] ?? null)));

        // /pets/{petId} -> {{baseUrl}}/pets/{{petId}} — our variable syntax.
        $request->setUrl('{{baseUrl}}' . preg_replace('/\{([^}]+)\}/', '{{$1}}', $path));

        $headers = [];
        $query = [];
        foreach ($this->resolveParameters(array_merge($pathParameters, (array) ($op['parameters'] ?? [])), $doc) as $param) {
            $name = (string) ($param['name'] ?? '');
            if ('' === $name) {
                continue;
            }
            $value = $this->parameterValue($param);
            match ($param['in'] ?? '') {
                'query' => $query[] = ['name' => $name, 'value' => $value],
                'header' => $headers[] = ['name' => $name, 'value' => $value],
                default => null,
            };
        }
        $request->setQueryParams($query);

        // Security: operation-level overrides the document default.
        $security = \array_key_exists('security', $op) ? (array) $op['security'] : $globalSecurity;
        $this->applySecurity($request, $security, $securitySchemes, $headers);
        $request->setHeaders($headers);

        // Body: prefer JSON content; sample generated from example or schema.
        $content = (array) ($op['requestBody']['content'] ?? []);
        $jsonContent = $content['application/json'] ?? $content[array_key_first($content) ?: ''] ?? null;
        if (\is_array($jsonContent)) {
            $sample = $this->sampleFromContent($jsonContent, $doc);
            if (null !== $sample) {
                $request->setBodyMode('json');
                $request->setBody((string) json_encode($sample, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
        }

        return $request;
    }

    /**
     * Maps the operation's first satisfiable security requirement onto the
     * request: bearer/basic via the auth column, apiKey as header/query with a
     * {{placeholder}} pointing at the environment variable.
     *
     * @param list<mixed>                       $security
     * @param array<string, mixed>              $securitySchemes
     * @param list<array{name:string,value:string}> $headers
     */
    private function applySecurity(ApiRequest $request, array $security, array $securitySchemes, array &$headers): void
    {
        foreach ($security as $requirement) {
            foreach (array_keys((array) $requirement) as $schemeName) {
                $scheme = $securitySchemes[$schemeName] ?? null;
                if (!\is_array($scheme)) {
                    continue;
                }
                $type = $scheme['type'] ?? '';
                $httpScheme = strtolower((string) ($scheme['scheme'] ?? ''));

                if ('http' === $type && 'bearer' === $httpScheme) {
                    $request->setAuth(['type' => 'bearer', 'token' => '{{' . $this->securityVariable($scheme) . '}}']);

                    return;
                }
                if (('http' === $type && 'basic' === $httpScheme) || 'basic' === $type) {
                    $request->setAuth(['type' => 'basic', 'username' => '{{basicUsername}}', 'password' => '{{basicPassword}}']);

                    return;
                }
                if ('apiKey' === $type) {
                    $keyName = (string) ($scheme['name'] ?? $schemeName);
                    $placeholder = '{{' . $this->securityVariable($scheme) . '}}';
                    if ('query' === ($scheme['in'] ?? 'header')) {
                        $request->setAuth(['type' => 'apikey', 'key' => $keyName, 'value' => $placeholder, 'addTo' => 'query']);
                    } else {
                        $headers[] = ['name' => $keyName, 'value' => $placeholder];
                    }

                    return;
                }
            }
        }
    }

    /**
     * The environment variable name a scheme's secret lives in: the apiKey
     * header name as-is (e.g. AccessKey), bearer as "bearerToken".
     *
     * @param array<string, mixed> $scheme
     */
    private function securityVariable(array $scheme): ?string
    {
        return match (true) {
            'apiKey' === ($scheme['type'] ?? '') => $this->stringOrNull($scheme['name'] ?? null),
            'http' === ($scheme['type'] ?? '') && 'bearer' === strtolower((string) ($scheme['scheme'] ?? '')) => 'bearerToken',
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function resolveServerUrl(array $data): ?string
    {
        // OpenAPI 3: servers[0].url with {var} defaults; Swagger 2: host+basePath.
        $server = $data['servers'][0] ?? null;
        if (\is_array($server) && isset($server['url'])) {
            $url = (string) $server['url'];
            foreach ((array) ($server['variables'] ?? []) as $name => $var) {
                $url = str_replace('{' . $name . '}', (string) ($var['default'] ?? ''), $url);
            }

            return $url;
        }

        if (isset($data['host'])) {
            $scheme = (string) (($data['schemes'][0] ?? null) ?: 'https');

            return $scheme . '://' . $data['host'] . (string) ($data['basePath'] ?? '');
        }

        return null;
    }

    /**
     * Resolves $ref'li parameters against #/components/parameters.
     *
     * @param list<mixed>          $parameters
     * @param array<string, mixed> $doc
     *
     * @return list<array<string, mixed>>
     */
    private function resolveParameters(array $parameters, array $doc): array
    {
        $resolved = [];
        foreach ($parameters as $param) {
            if (\is_array($param) && isset($param['$ref'])) {
                $param = $this->deref((string) $param['$ref'], $doc);
            }
            if (\is_array($param)) {
                $resolved[] = $param;
            }
        }

        return $resolved;
    }

    /** @param array<string, mixed> $param */
    private function parameterValue(array $param): string
    {
        $schema = (array) ($param['schema'] ?? []);
        $value = $param['example'] ?? $schema['example'] ?? $schema['default'] ?? $param['default'] ?? null;

        return null === $value ? '' : (\is_scalar($value) ? (string) $value : (string) json_encode($value));
    }

    /**
     * A body sample: media-type example, else generated from the schema.
     *
     * @param array<string, mixed> $content Media-type object
     * @param array<string, mixed> $doc
     */
    private function sampleFromContent(array $content, array $doc): mixed
    {
        if (\array_key_exists('example', $content)) {
            return $content['example'];
        }
        $first = null;
        foreach ((array) ($content['examples'] ?? []) as $ex) {
            $first = \is_array($ex) ? ($ex['value'] ?? null) : null;
            break;
        }
        if (null !== $first) {
            return $first;
        }

        $schema = $content['schema'] ?? null;

        return \is_array($schema) ? $this->sampleFromSchema($schema, $doc, self::SAMPLE_DEPTH) : null;
    }

    /**
     * Skeleton value from a JSON schema, depth-limited and cycle-safe.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $doc
     */
    private function sampleFromSchema(array $schema, array $doc, int $depth): mixed
    {
        if ($depth <= 0) {
            return null;
        }
        if (isset($schema['$ref'])) {
            $target = $this->deref((string) $schema['$ref'], $doc);

            return \is_array($target) ? $this->sampleFromSchema($target, $doc, $depth - 1) : null;
        }
        if (\array_key_exists('example', $schema)) {
            return $schema['example'];
        }
        if (\array_key_exists('default', $schema)) {
            return $schema['default'];
        }
        if (isset($schema['enum'][0])) {
            return $schema['enum'][0];
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $combiner) {
            if (isset($schema[$combiner][0]) && \is_array($schema[$combiner][0])) {
                return $this->sampleFromSchema($schema[$combiner][0], $doc, $depth - 1);
            }
        }

        return match ($schema['type'] ?? (isset($schema['properties']) ? 'object' : null)) {
            'object' => (object) array_map(
                fn ($prop) => \is_array($prop) ? $this->sampleFromSchema($prop, $doc, $depth - 1) : null,
                (array) ($schema['properties'] ?? []),
            ),
            'array' => \is_array($schema['items'] ?? null) ? [$this->sampleFromSchema($schema['items'], $doc, $depth - 1)] : [],
            'integer' => 0,
            'number' => 0,
            'boolean' => false,
            'string' => (string) ($schema['format'] ?? 'string'),
            default => null,
        };
    }

    /**
     * "#/components/schemas/Pet" -> the node at that pointer, or null.
     *
     * @param array<string, mixed> $doc
     */
    private function deref(string $ref, array $doc): mixed
    {
        if (!str_starts_with($ref, '#/')) {
            return null;
        }
        $node = $doc;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? $value : null;
    }
}
