<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626070505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_run ADD batch_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_run ADD iteration INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE flow_run ALTER COLUMN iteration DROP DEFAULT');
        $this->addSql("ALTER TABLE flow_run ADD iteration_data JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE flow_run ALTER COLUMN iteration_data DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_run DROP batch_id');
        $this->addSql('ALTER TABLE flow_run DROP iteration');
        $this->addSql('ALTER TABLE flow_run DROP iteration_data');
    }
}
