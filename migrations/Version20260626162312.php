<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626162312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE flow_group (id UUID NOT NULL, name VARCHAR(150) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F31A3BB982D40A1F ON flow_group (workspace_id)');
        $this->addSql('ALTER TABLE flow_group ADD CONSTRAINT FK_F31A3BB982D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE test_flow ADD group_position INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE test_flow ADD flow_group_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE test_flow ADD CONSTRAINT FK_57B9FCBF6D1E161E FOREIGN KEY (flow_group_id) REFERENCES flow_group (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_57B9FCBF6D1E161E ON test_flow (flow_group_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_group DROP CONSTRAINT FK_F31A3BB982D40A1F');
        $this->addSql('DROP TABLE flow_group');
        $this->addSql('ALTER TABLE test_flow DROP CONSTRAINT FK_57B9FCBF6D1E161E');
        $this->addSql('DROP INDEX IDX_57B9FCBF6D1E161E');
        $this->addSql('ALTER TABLE test_flow DROP group_position');
        $this->addSql('ALTER TABLE test_flow DROP flow_group_id');
    }
}
