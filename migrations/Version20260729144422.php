<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260729144422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketplace catalog: catalog_api + immutable catalog_api_version; api_collection.source_version_id provenance';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE catalog_api (id UUID NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, publisher VARCHAR(150) DEFAULT NULL, category VARCHAR(100) DEFAULT NULL, description TEXT DEFAULT NULL, logo VARCHAR(16) DEFAULT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_catalog_api_slug ON catalog_api (slug)');
        $this->addSql('CREATE TABLE catalog_api_version (id UUID NOT NULL, label VARCHAR(50) NOT NULL, changelog TEXT DEFAULT NULL, spec JSON NOT NULL, spec_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, catalog_api_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4396860AE6922171 ON catalog_api_version (catalog_api_id)');
        $this->addSql('CREATE INDEX idx_catalog_version_api ON catalog_api_version (catalog_api_id, created_at)');
        $this->addSql('ALTER TABLE catalog_api_version ADD CONSTRAINT FK_4396860AE6922171 FOREIGN KEY (catalog_api_id) REFERENCES catalog_api (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_collection ADD source_version_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE api_collection ADD CONSTRAINT FK_3BE7192337085621 FOREIGN KEY (source_version_id) REFERENCES catalog_api_version (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3BE7192337085621 ON api_collection (source_version_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_api_version DROP CONSTRAINT FK_4396860AE6922171');
        $this->addSql('DROP TABLE catalog_api');
        $this->addSql('DROP TABLE catalog_api_version');
        $this->addSql('ALTER TABLE api_collection DROP CONSTRAINT FK_3BE7192337085621');
        $this->addSql('DROP INDEX IDX_3BE7192337085621');
        $this->addSql('ALTER TABLE api_collection DROP source_version_id');
    }
}
