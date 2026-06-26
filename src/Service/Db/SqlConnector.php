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

        $pdo = new \PDO($dsn, (string) $conn->getUsername(), $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 10,
        ]);

        $stmt = $pdo->query($query);
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
     * @param array<string, mixed> $data
     */
    private function jsonDisplay(array $data): string
    {
        return (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
