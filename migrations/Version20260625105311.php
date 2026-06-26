<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625105311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE response_history (id UUID NOT NULL, method VARCHAR(10) NOT NULL, url TEXT NOT NULL, status_code INT DEFAULT NULL, duration_ms INT NOT NULL, size INT NOT NULL, environment_name VARCHAR(200) DEFAULT NULL, response_body TEXT DEFAULT NULL, response_headers JSON NOT NULL, error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, api_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8C00363585D4C4B4 ON response_history (api_request_id)');
        $this->addSql('CREATE INDEX idx_history_request ON response_history (api_request_id, created_at)');
        $this->addSql('ALTER TABLE response_history ADD CONSTRAINT FK_8C00363585D4C4B4 FOREIGN KEY (api_request_id) REFERENCES api_request (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE response_history DROP CONSTRAINT FK_8C00363585D4C4B4');
        $this->addSql('DROP TABLE response_history');
    }
}
