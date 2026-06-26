<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626173817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE flow_group_run (id UUID NOT NULL, batch_id VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, total INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, flow_group_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C1C0EF266D1E161E ON flow_group_run (flow_group_id)');
        $this->addSql('CREATE INDEX idx_group_run_group ON flow_group_run (flow_group_id, created_at)');
        $this->addSql('ALTER TABLE flow_group_run ADD CONSTRAINT FK_C1C0EF266D1E161E FOREIGN KEY (flow_group_id) REFERENCES flow_group (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_group_run DROP CONSTRAINT FK_C1C0EF266D1E161E');
        $this->addSql('DROP TABLE flow_group_run');
    }
}
