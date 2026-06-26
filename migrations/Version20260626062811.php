<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626062811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step ADD retry_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE flow_step ALTER COLUMN retry_enabled DROP DEFAULT');
        $this->addSql('ALTER TABLE flow_step ADD retry_max INT NOT NULL DEFAULT 5');
        $this->addSql('ALTER TABLE flow_step ALTER COLUMN retry_max DROP DEFAULT');
        $this->addSql('ALTER TABLE flow_step ADD retry_delay_ms INT NOT NULL DEFAULT 1000');
        $this->addSql('ALTER TABLE flow_step ALTER COLUMN retry_delay_ms DROP DEFAULT');
        $this->addSql('ALTER TABLE step_result ADD attempts INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE step_result ALTER COLUMN attempts DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step DROP retry_enabled');
        $this->addSql('ALTER TABLE flow_step DROP retry_max');
        $this->addSql('ALTER TABLE flow_step DROP retry_delay_ms');
        $this->addSql('ALTER TABLE step_result DROP attempts');
    }
}
