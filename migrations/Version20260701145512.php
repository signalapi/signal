<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701145512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_run ADD share_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_run ADD share_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B1B9B3B2D6594DD6 ON flow_run (share_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_B1B9B3B2D6594DD6');
        $this->addSql('ALTER TABLE flow_run DROP share_token');
        $this->addSql('ALTER TABLE flow_run DROP share_expires_at');
    }
}
