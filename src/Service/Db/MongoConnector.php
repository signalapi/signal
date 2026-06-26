<?php

namespace App\Service\Db;

use App\Entity\DbConnection;

/**
 * MongoDB connector. A query is a JSON spec, e.g.
 *   {"collection": "subscriptions", "filter": {"userId": "{{userId}}"}, "limit": 5}
 *   {"collection": "subscriptions", "operation": "count", "filter": {"status": "active"}}
 * Result is normalised to {count, documents[]}.
 */
class MongoConnector
{
    /**
     * @return array{data: array<string, mixed>, display: string}
     */
    public function run(DbConnection $conn, string $password, string $query): array
    {
        if (!class_exists(\MongoDB\Client::class)) {
            throw new \RuntimeException('mongodb eklentisi/kütüphanesi yüklü değil.');
        }

        $spec = json_decode(trim($query) ?: '{}', true);
        if (!\is_array($spec) || !isset($spec['collection'])) {
            throw new \InvalidArgumentException('Mongo sorgusu JSON olmalı ve "collection" içermeli.');
        }

        $client = new \MongoDB\Client($this->buildUri($conn, $password));
        $collection = $client
            ->selectDatabase((string) $conn->getDatabaseName())
            ->selectCollection((string) $spec['collection']);

        $filter = (array) ($spec['filter'] ?? []);
        $operation = $spec['operation'] ?? 'find';
        $typeMap = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

        if ('count' === $operation) {
            $count = $collection->countDocuments($filter);
            $data = ['count' => $count, 'documents' => []];
        } else {
            $options = ['typeMap' => $typeMap];
            if (isset($spec['limit'])) {
                $options['limit'] = (int) $spec['limit'];
            }
            $documents = $collection->find($filter, $options)->toArray();
            $data = ['count' => \count($documents), 'documents' => $documents];
        }

        return ['data' => $data, 'display' => (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)];
    }

    private function buildUri(DbConnection $conn, string $password): string
    {
        $auth = '';
        if (null !== $conn->getUsername() && '' !== $conn->getUsername()) {
            $auth = rawurlencode($conn->getUsername()) . ':' . rawurlencode($password) . '@';
        }

        $uri = sprintf('mongodb://%s%s:%d', $auth, $conn->getHost(), $conn->getPort());

        $authSource = $conn->getOptions()['authSource'] ?? null;
        if ($authSource) {
            $uri .= '/?authSource=' . rawurlencode((string) $authSource);
        }

        return $uri;
    }
}
