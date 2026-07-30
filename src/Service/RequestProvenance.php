<?php

namespace App\Service;

use App\Entity\ApiRequest;

/**
 * Canonical identity + content hash for imported requests. Both importers use
 * this so a future "spec updated, what changed?" diff can compare apples to
 * apples: origin_hash != current hash(...) means we edited it locally,
 * origin_hash != new spec's hash means upstream changed it.
 */
final class RequestProvenance
{
    /** Stable upstream identity: OpenAPI operationId, else "get /pets/{id}". */
    public static function key(string $method, string $path, ?string $operationId = null): string
    {
        if (null !== $operationId && '' !== $operationId) {
            return $operationId;
        }

        return strtolower($method) . ' ' . $path;
    }

    /** SHA-256 over the fields an import defines; order-stable. */
    public static function hash(ApiRequest $request): string
    {
        return hash('sha256', (string) json_encode([
            'method' => strtoupper($request->getMethod()),
            'url' => $request->getUrl(),
            'headers' => $request->getHeaders(),
            'query' => $request->getQueryParams(),
            'bodyMode' => $request->getBodyMode(),
            'body' => $request->getBody(),
            'auth' => $request->getAuth(),
        ]));
    }
}
