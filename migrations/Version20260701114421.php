<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701114421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step ADD called_flow_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_step ADD CONSTRAINT FK_FD53213BDC69D90B FOREIGN KEY (called_flow_id) REFERENCES test_flow (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_FD53213BDC69D90B ON flow_step (called_flow_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step DROP CONSTRAINT FK_FD53213BDC69D90B');
        $this->addSql('DROP INDEX IDX_FD53213BDC69D90B');
        $this->addSql('ALTER TABLE flow_step DROP called_flow_id');
    }
}
