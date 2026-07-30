<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730131650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'api_collection.source_collection: in-app forks point at the collection they came from';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_collection ADD source_collection_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE api_collection ADD CONSTRAINT FK_3BE719239425879F FOREIGN KEY (source_collection_id) REFERENCES api_collection (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3BE719239425879F ON api_collection (source_collection_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_collection DROP CONSTRAINT FK_3BE719239425879F');
        $this->addSql('DROP INDEX IDX_3BE719239425879F');
        $this->addSql('ALTER TABLE api_collection DROP source_collection_id');
    }
}
