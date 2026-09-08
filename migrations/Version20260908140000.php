<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The workspace mock server: stubbed endpoints (mock_route) served publicly
 * under /mock/{workspace.mock_token}/… so tests can cut external dependencies
 * and stay deterministic.
 */
final class Version20260908140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mock_route table and workspace mock_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workspace ADD mock_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_WORKSPACE_MOCK_TOKEN ON workspace (mock_token)');
        $this->addSql('CREATE TABLE mock_route (
            id UUID NOT NULL,
            workspace_id UUID NOT NULL,
            method VARCHAR(10) NOT NULL,
            path VARCHAR(500) NOT NULL,
            response_status INT NOT NULL,
            content_type VARCHAR(100) NOT NULL,
            response_body TEXT DEFAULT NULL,
            delay_ms INT NOT NULL,
            active BOOLEAN NOT NULL,
            hits INT NOT NULL,
            last_hit_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_MOCK_ROUTE_WORKSPACE ON mock_route (workspace_id)');
        $this->addSql('ALTER TABLE mock_route ADD CONSTRAINT FK_MOCK_ROUTE_WORKSPACE FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mock_route');
        $this->addSql('DROP INDEX UNIQ_WORKSPACE_MOCK_TOKEN');
        $this->addSql('ALTER TABLE workspace DROP mock_token');
    }
}
