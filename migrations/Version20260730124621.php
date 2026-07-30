<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730124621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'flow_run.triggered_by: which user set the run off (null for scheduled runs)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_run ADD triggered_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_run ADD CONSTRAINT FK_B1B9B3B263C5923F FOREIGN KEY (triggered_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_B1B9B3B263C5923F ON flow_run (triggered_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_run DROP CONSTRAINT FK_B1B9B3B263C5923F');
        $this->addSql('DROP INDEX IDX_B1B9B3B263C5923F');
        $this->addSql('ALTER TABLE flow_run DROP triggered_by_id');
    }
}
