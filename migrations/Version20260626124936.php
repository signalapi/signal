<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626124936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE response_example (id UUID NOT NULL, name VARCHAR(150) NOT NULL, source VARCHAR(12) NOT NULL, status_code INT DEFAULT NULL, method VARCHAR(10) NOT NULL, url TEXT NOT NULL, response_body TEXT DEFAULT NULL, response_headers JSON NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, api_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C556DDE185D4C4B4 ON response_example (api_request_id)');
        $this->addSql('CREATE INDEX idx_example_request ON response_example (api_request_id, position)');
        $this->addSql('ALTER TABLE response_example ADD CONSTRAINT FK_C556DDE185D4C4B4 FOREIGN KEY (api_request_id) REFERENCES api_request (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE response_example DROP CONSTRAINT FK_C556DDE185D4C4B4');
        $this->addSql('DROP TABLE response_example');
    }
}
