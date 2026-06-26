<?php

namespace App\Service\Db;

use App\Entity\DbConnection;
use App\Service\SecretCipher;
use App\Service\VariableResolver;

/**
 * Runs a query against a DbConnection, interpolating {{variables}} from the run
 * context first, and returns a normalised result for assertions/extractions.
 */
class DbQueryRunner
{
    public function __construct(
        private readonly SqlConnector $sql,
        private readonly RedisConnector $redis,
        private readonly MongoConnector $mongo,
        private readonly SecretCipher $cipher,
        private readonly VariableResolver $resolver,
    ) {
    }

    /**
     * @param array<string, string> $vars
     */
    public function run(DbConnection $connection, ?string $query, array $vars = []): DbStepResult
    {
        $resolvedQuery = $this->resolver->resolve($query ?? '', $vars) ?? '';
        $password = $connection->hasPassword() ? $this->cipher->decrypt((string) $connection->getPasswordEncrypted()) : '';

        $start = microtime(true);

        try {
            $result = match ($connection->getType()) {
                DbConnection::TYPE_POSTGRES, DbConnection::TYPE_MYSQL => $this->sql->run($connection, $password, $resolvedQuery),
                DbConnection::TYPE_REDIS => $this->redis->run($connection, $password, $resolvedQuery),
                DbConnection::TYPE_MONGO => $this->mongo->run($connection, $password, $resolvedQuery),
                default => throw new \InvalidArgumentException('Bilinmeyen bağlantı tipi: ' . $connection->getType()),
            };

            return new DbStepResult(
                ok: true,
                data: $result['data'],
                display: $result['display'],
                durationMs: (microtime(true) - $start) * 1000,
            );
        } catch (\Throwable $e) {
            return new DbStepResult(
                ok: false,
                durationMs: (microtime(true) - $start) * 1000,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Quick connectivity check used by the "Test connection" button.
     */
    public function test(DbConnection $connection): DbStepResult
    {
        $probe = match ($connection->getType()) {
            DbConnection::TYPE_POSTGRES, DbConnection::TYPE_MYSQL => 'SELECT 1',
            DbConnection::TYPE_REDIS => 'PING',
            DbConnection::TYPE_MONGO => '{"collection":"__probe__","operation":"count","filter":{}}',
            default => '',
        };

        return $this->run($connection, $probe, []);
    }
}
