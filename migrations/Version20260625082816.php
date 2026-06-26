<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625082816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE db_connection (id UUID NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(20) NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, database_name VARCHAR(150) DEFAULT NULL, username VARCHAR(150) DEFAULT NULL, password_encrypted TEXT DEFAULT NULL, options JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_246C362F82D40A1F ON db_connection (workspace_id)');
        $this->addSql('ALTER TABLE db_connection ADD CONSTRAINT FK_246C362F82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Add with a default so existing rows backfill to 'http', then drop the default
        // (the type is set explicitly in PHP for every new step).
        $this->addSql("ALTER TABLE flow_step ADD type VARCHAR(10) NOT NULL DEFAULT 'http'");
        $this->addSql('ALTER TABLE flow_step ALTER COLUMN type DROP DEFAULT');
        $this->addSql('ALTER TABLE flow_step ADD query TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_step ADD db_connection_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_step ADD CONSTRAINT FK_FD53213B984878CF FOREIGN KEY (db_connection_id) REFERENCES db_connection (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_FD53213B984878CF ON flow_step (db_connection_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE db_connection DROP CONSTRAINT FK_246C362F82D40A1F');
        $this->addSql('DROP TABLE db_connection');
        $this->addSql('ALTER TABLE flow_step DROP CONSTRAINT FK_FD53213B984878CF');
        $this->addSql('DROP INDEX IDX_FD53213B984878CF');
        $this->addSql('ALTER TABLE flow_step DROP type');
        $this->addSql('ALTER TABLE flow_step DROP query');
        $this->addSql('ALTER TABLE flow_step DROP db_connection_id');
    }
}
