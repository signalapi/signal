<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625085838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_token (id UUID NOT NULL, name VARCHAR(150) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_prefix VARCHAR(16) NOT NULL, revoked BOOLEAN NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7BA2F5EB82D40A1F ON api_token (workspace_id)');
        $this->addSql('CREATE INDEX IDX_7BA2F5EBB03A8386 ON api_token (created_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_token_hash ON api_token (token_hash)');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EBB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE test_flow ADD schedule_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE test_flow ALTER COLUMN schedule_enabled DROP DEFAULT');
        $this->addSql('ALTER TABLE test_flow ADD cron_expression VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE test_flow ADD last_scheduled_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token DROP CONSTRAINT FK_7BA2F5EB82D40A1F');
        $this->addSql('ALTER TABLE api_token DROP CONSTRAINT FK_7BA2F5EBB03A8386');
        $this->addSql('DROP TABLE api_token');
        $this->addSql('ALTER TABLE test_flow DROP schedule_enabled');
        $this->addSql('ALTER TABLE test_flow DROP cron_expression');
        $this->addSql('ALTER TABLE test_flow DROP last_scheduled_run_at');
    }
}
