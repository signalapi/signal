<?php

namespace App\Service\Db;

use App\Entity\DbConnection;

/**
 * Redis connector via phpredis. A query is a single command line, e.g.
 *   GET session:{{userId}}
 *   EXISTS lock:order:42
 *   HGETALL user:42
 * The result is normalised to {command, value, exists}.
 */
class RedisConnector
{
    /**
     * @return array{data: array<string, mixed>, display: string}
     */
    public function run(DbConnection $conn, string $password, string $query): array
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('phpredis eklentisi yüklü değil.');
        }

        $redis = new \Redis();
        $redis->connect($conn->getHost(), $conn->getPort(), 5.0);
        if ('' !== $password) {
            $redis->auth($password);
        }
        $db = (int) ($conn->getOptions()['db'] ?? 0);
        if ($db > 0) {
            $redis->select($db);
        }

        $tokens = preg_split('/\s+/', trim($query)) ?: [];
        if ([] === $tokens || '' === $tokens[0]) {
            throw new \InvalidArgumentException('Boş Redis komutu.');
        }

        $command = strtoupper(array_shift($tokens));
        $raw = $redis->rawCommand($command, ...$tokens);

        // phpredis returns false for a missing key (nil reply).
        $value = false === $raw ? null : $raw;
        $data = [
            'command' => $command,
            'value' => $value,
            'exists' => false !== $raw && null !== $raw,
        ];

        $redis->close();

        return ['data' => $data, 'display' => (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)];
    }
}
