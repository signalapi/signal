<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626153010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step ADD req_method VARCHAR(10) DEFAULT \'GET\' NOT NULL');
        $this->addSql('ALTER TABLE flow_step ADD req_url TEXT DEFAULT \'\' NOT NULL');
        $this->addSql("ALTER TABLE flow_step ADD req_headers JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE flow_step ADD req_params JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE flow_step ADD req_body_mode VARCHAR(10) DEFAULT \'none\' NOT NULL');
        $this->addSql('ALTER TABLE flow_step ADD req_body TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE flow_step ADD req_auth JSON NOT NULL DEFAULT '{}'");
        // Backfill each existing step's flow-owned copy from its origin request.
        $this->addSql('UPDATE flow_step s SET req_method = r.method, req_url = r.url, req_headers = r.headers, req_params = r.query_params, req_body_mode = r.body_mode, req_body = r.body, req_auth = r.auth FROM api_request r WHERE s.api_request_id = r.id');
        // The entity declares no default for the JSON columns.
        $this->addSql('ALTER TABLE flow_step ALTER COLUMN req_headers DROP DEFAULT');
        $this->addSql('ALTER TABLE flow_step ALTER COLUMN req_params DROP DEFAULT');
        $this->addSql('ALTER TABLE flow_step ALTER COLUMN req_auth DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step DROP req_method');
        $this->addSql('ALTER TABLE flow_step DROP req_url');
        $this->addSql('ALTER TABLE flow_step DROP req_headers');
        $this->addSql('ALTER TABLE flow_step DROP req_params');
        $this->addSql('ALTER TABLE flow_step DROP req_body_mode');
        $this->addSql('ALTER TABLE flow_step DROP req_body');
        $this->addSql('ALTER TABLE flow_step DROP req_auth');
    }
}
