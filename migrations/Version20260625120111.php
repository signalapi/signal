<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625120111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cookie (id UUID NOT NULL, domain VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, value TEXT NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, secure BOOLEAN NOT NULL, host_only BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8AE0BA6682D40A1F ON cookie (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cookie ON cookie (workspace_id, domain, path, name)');
        $this->addSql('ALTER TABLE cookie ADD CONSTRAINT FK_8AE0BA6682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cookie DROP CONSTRAINT FK_8AE0BA6682D40A1F');
        $this->addSql('DROP TABLE cookie');
    }
}
