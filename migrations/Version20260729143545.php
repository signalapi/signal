<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260729143545 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import provenance: api_collection.source_type + api_request.origin_key/origin_hash for future spec-version diffs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_collection ADD source_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE api_request ADD origin_key VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE api_request ADD origin_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_collection DROP source_type');
        $this->addSql('ALTER TABLE api_request DROP origin_key');
        $this->addSql('ALTER TABLE api_request DROP origin_hash');
    }
}
