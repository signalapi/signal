<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MCP servers as a catalogued resource: the endpoint a workspace tests against
 * and the tools it publishes. Before this a step carried the URL, the auth
 * header and the tool name inline, repeated per step, with nothing anywhere
 * listing which servers a workspace even tests.
 */
final class Version20260916130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mcp_server table and flow_step.mcp_server_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mcp_server (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                name VARCHAR(150) NOT NULL,
                description TEXT DEFAULT NULL,
                url TEXT NOT NULL,
                headers JSON NOT NULL,
                tools JSON NOT NULL,
                tools_refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                refresh_error TEXT DEFAULT NULL,
                server_name VARCHAR(150) DEFAULT NULL,
                protocol_version VARCHAR(40) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_mcp_server_workspace ON mcp_server (workspace_id)');
        $this->addSql('ALTER TABLE mcp_server ADD CONSTRAINT fk_mcp_server_workspace FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE flow_step ADD mcp_server_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_step ADD CONSTRAINT fk_flow_step_mcp_server FOREIGN KEY (mcp_server_id) REFERENCES mcp_server (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_flow_step_mcp_server ON flow_step (mcp_server_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_step DROP CONSTRAINT fk_flow_step_mcp_server');
        $this->addSql('DROP INDEX idx_flow_step_mcp_server');
        $this->addSql('ALTER TABLE flow_step DROP mcp_server_id');
        $this->addSql('DROP TABLE mcp_server');
    }
}
