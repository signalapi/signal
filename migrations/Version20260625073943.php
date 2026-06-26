<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625073943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_collection (id UUID NOT NULL, name VARCHAR(200) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3BE7192382D40A1F ON api_collection (workspace_id)');
        $this->addSql('CREATE TABLE api_request (id UUID NOT NULL, name VARCHAR(200) NOT NULL, method VARCHAR(10) NOT NULL, url TEXT NOT NULL, headers JSON NOT NULL, query_params JSON NOT NULL, body_mode VARCHAR(10) NOT NULL, body TEXT DEFAULT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, collection_id UUID NOT NULL, folder_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D6A9FBF8514956FD ON api_request (collection_id)');
        $this->addSql('CREATE INDEX IDX_D6A9FBF8162CB942 ON api_request (folder_id)');
        $this->addSql('CREATE TABLE env_variable (id UUID NOT NULL, name VARCHAR(200) NOT NULL, value TEXT DEFAULT NULL, secret BOOLEAN NOT NULL, environment_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_365543D5903E3A94 ON env_variable (environment_id)');
        $this->addSql('CREATE TABLE environment (id UUID NOT NULL, name VARCHAR(200) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4626DE2282D40A1F ON environment (workspace_id)');
        $this->addSql('CREATE TABLE folder (id UUID NOT NULL, name VARCHAR(200) NOT NULL, position INT NOT NULL, collection_id UUID NOT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ECA209CD514956FD ON folder (collection_id)');
        $this->addSql('CREATE INDEX IDX_ECA209CD727ACA70 ON folder (parent_id)');
        $this->addSql('ALTER TABLE api_collection ADD CONSTRAINT FK_3BE7192382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_request ADD CONSTRAINT FK_D6A9FBF8514956FD FOREIGN KEY (collection_id) REFERENCES api_collection (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_request ADD CONSTRAINT FK_D6A9FBF8162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE env_variable ADD CONSTRAINT FK_365543D5903E3A94 FOREIGN KEY (environment_id) REFERENCES environment (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE environment ADD CONSTRAINT FK_4626DE2282D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE folder ADD CONSTRAINT FK_ECA209CD514956FD FOREIGN KEY (collection_id) REFERENCES api_collection (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE folder ADD CONSTRAINT FK_ECA209CD727ACA70 FOREIGN KEY (parent_id) REFERENCES folder (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_collection DROP CONSTRAINT FK_3BE7192382D40A1F');
        $this->addSql('ALTER TABLE api_request DROP CONSTRAINT FK_D6A9FBF8514956FD');
        $this->addSql('ALTER TABLE api_request DROP CONSTRAINT FK_D6A9FBF8162CB942');
        $this->addSql('ALTER TABLE env_variable DROP CONSTRAINT FK_365543D5903E3A94');
        $this->addSql('ALTER TABLE environment DROP CONSTRAINT FK_4626DE2282D40A1F');
        $this->addSql('ALTER TABLE folder DROP CONSTRAINT FK_ECA209CD514956FD');
        $this->addSql('ALTER TABLE folder DROP CONSTRAINT FK_ECA209CD727ACA70');
        $this->addSql('DROP TABLE api_collection');
        $this->addSql('DROP TABLE api_request');
        $this->addSql('DROP TABLE env_variable');
        $this->addSql('DROP TABLE environment');
        $this->addSql('DROP TABLE folder');
    }
}
