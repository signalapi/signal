<?php

namespace App\Service\Db;

use App\Entity\DbConnection;

/**
 * PostgreSQL & MySQL connector via PDO. The query result is normalised to
 * {rowCount, rows[]} so assertions can target rowCount or rows.0.column.
 */
class SqlConnector
{
    /**
     * @return array{data: array<string, mixed>, display: string}
     */
    public function run(DbConnection $conn, string $password, string $query): array
    {
        $driver = DbConnection::TYPE_MYSQL === $conn->getType() ? 'mysql' : 'pgsql';
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s',
            $driver,
            $conn->getHost(),
            $conn->getPort(),
            (string) $conn->getDatabaseName(),
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
        ];

        // Connect + query with one reconnect retry: a remote DB over a VPN can
        // drop the socket mid-handshake and answer "server has gone away"
        // (2006/2013). A single fresh connection usually rides out a transient blip.
        $attempt = 0;
        while (true) {
            try {
                $pdo = new \PDO($dsn, (string) $conn->getUsername(), $password, $options);
                $stmt = $pdo->query($query);
                break;
            } catch (\PDOException $e) {
                if ($attempt < 1 && $this->isTransient($e)) {
                    ++$attempt;
                    usleep(400_000);
                    continue;
                }
                throw $e;
            }
        }

        $rows = [];
        if ($stmt->columnCount() > 0) {
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $data = [
            'rowCount' => $stmt->columnCount() > 0 ? \count($rows) : $stmt->rowCount(),
            'rows' => $rows,
        ];

        return ['data' => $data, 'display' => $this->jsonDisplay($data)];
    }

    /**
     * A dropped-socket / gone-away class of error worth one reconnect.
     */
    private function isTransient(\PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'gone away')          // MySQL 2006
            || str_contains($msg, 'lost connection')     // MySQL 2013
            || str_contains($msg, 'broken pipe')
            || str_contains($msg, 'connection reset')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'timed out');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonDisplay(array $data): string
    {
        return (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
