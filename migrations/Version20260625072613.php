<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625072613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE merchant (id UUID NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_merchant_slug ON merchant (slug)');
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(120) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, merchant_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8D93D6496796D554 ON "user" (merchant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
        $this->addSql('CREATE TABLE workspace (id UUID NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, merchant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8D9400196796D554 ON workspace (merchant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_workspace_merchant_slug ON workspace (merchant_id, slug)');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8D93D6496796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workspace ADD CONSTRAINT FK_8D9400196796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8D93D6496796D554');
        $this->addSql('ALTER TABLE workspace DROP CONSTRAINT FK_8D9400196796D554');
        $this->addSql('DROP TABLE merchant');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE workspace');
    }
}
