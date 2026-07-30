<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730120513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'catalog_api: visibility (public|merchant|workspace), verified flag and owner merchant/workspace';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_api ADD visibility VARCHAR(20) DEFAULT \'public\' NOT NULL');
        $this->addSql('ALTER TABLE catalog_api ADD verified BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE catalog_api ADD owner_merchant_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_api ADD owner_workspace_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_api ADD CONSTRAINT FK_721632CD61720AD2 FOREIGN KEY (owner_merchant_id) REFERENCES merchant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE catalog_api ADD CONSTRAINT FK_721632CD8609C8D5 FOREIGN KEY (owner_workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_721632CD61720AD2 ON catalog_api (owner_merchant_id)');
        $this->addSql('CREATE INDEX IDX_721632CD8609C8D5 ON catalog_api (owner_workspace_id)');
        $this->addSql('CREATE INDEX idx_catalog_visibility ON catalog_api (visibility)');
        // Entries that existed before visibility was introduced are the
        // platform's own curation: public and verified.
        $this->addSql("UPDATE catalog_api SET visibility = 'public', verified = true WHERE owner_merchant_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_api DROP CONSTRAINT FK_721632CD61720AD2');
        $this->addSql('ALTER TABLE catalog_api DROP CONSTRAINT FK_721632CD8609C8D5');
        $this->addSql('DROP INDEX IDX_721632CD61720AD2');
        $this->addSql('DROP INDEX IDX_721632CD8609C8D5');
        $this->addSql('DROP INDEX idx_catalog_visibility');
        $this->addSql('ALTER TABLE catalog_api DROP visibility');
        $this->addSql('ALTER TABLE catalog_api DROP verified');
        $this->addSql('ALTER TABLE catalog_api DROP owner_merchant_id');
        $this->addSql('ALTER TABLE catalog_api DROP owner_workspace_id');
    }
}
