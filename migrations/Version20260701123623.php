<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701123623 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_factory (id UUID NOT NULL, name VARCHAR(80) NOT NULL, kind VARCHAR(20) NOT NULL, config JSON NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9F3D480882D40A1F ON data_factory (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_factory_ws_name ON data_factory (workspace_id, name)');
        $this->addSql('ALTER TABLE data_factory ADD CONSTRAINT FK_9F3D480882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_factory DROP CONSTRAINT FK_9F3D480882D40A1F');
        $this->addSql('DROP TABLE data_factory');
    }
}
