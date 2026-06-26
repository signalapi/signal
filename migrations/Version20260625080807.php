<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625080807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE flow_run (id UUID NOT NULL, environment_name VARCHAR(200) DEFAULT NULL, status VARCHAR(20) NOT NULL, trigger VARCHAR(20) NOT NULL, total_steps INT NOT NULL, passed_steps INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, flow_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B1B9B3B27EB60D1B ON flow_run (flow_id)');
        $this->addSql('CREATE TABLE flow_step (id UUID NOT NULL, name VARCHAR(200) NOT NULL, position INT NOT NULL, extractions JSON NOT NULL, assertions JSON NOT NULL, flow_id UUID NOT NULL, api_request_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FD53213B7EB60D1B ON flow_step (flow_id)');
        $this->addSql('CREATE INDEX IDX_FD53213B85D4C4B4 ON flow_step (api_request_id)');
        $this->addSql('CREATE TABLE step_result (id UUID NOT NULL, position INT NOT NULL, label VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL, request_method VARCHAR(10) DEFAULT NULL, request_url TEXT DEFAULT NULL, response_status INT DEFAULT NULL, duration_ms INT DEFAULT NULL, response_body TEXT DEFAULT NULL, assertion_results JSON NOT NULL, extracted_vars JSON NOT NULL, error TEXT DEFAULT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3E740C6984E3FEC4 ON step_result (run_id)');
        $this->addSql('CREATE TABLE test_flow (id UUID NOT NULL, name VARCHAR(200) NOT NULL, description TEXT DEFAULT NULL, stop_on_failure BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, default_environment_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_57B9FCBF82D40A1F ON test_flow (workspace_id)');
        $this->addSql('CREATE INDEX IDX_57B9FCBF14B65AD6 ON test_flow (default_environment_id)');
        $this->addSql('ALTER TABLE flow_run ADD CONSTRAINT FK_B1B9B3B27EB60D1B FOREIGN KEY (flow_id) REFERENCES test_flow (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE flow_step ADD CONSTRAINT FK_FD53213B7EB60D1B FOREIGN KEY (flow_id) REFERENCES test_flow (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE flow_step ADD CONSTRAINT FK_FD53213B85D4C4B4 FOREIGN KEY (api_request_id) REFERENCES api_request (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE step_result ADD CONSTRAINT FK_3E740C6984E3FEC4 FOREIGN KEY (run_id) REFERENCES flow_run (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE test_flow ADD CONSTRAINT FK_57B9FCBF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE test_flow ADD CONSTRAINT FK_57B9FCBF14B65AD6 FOREIGN KEY (default_environment_id) REFERENCES environment (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_run DROP CONSTRAINT FK_B1B9B3B27EB60D1B');
        $this->addSql('ALTER TABLE flow_step DROP CONSTRAINT FK_FD53213B7EB60D1B');
        $this->addSql('ALTER TABLE flow_step DROP CONSTRAINT FK_FD53213B85D4C4B4');
        $this->addSql('ALTER TABLE step_result DROP CONSTRAINT FK_3E740C6984E3FEC4');
        $this->addSql('ALTER TABLE test_flow DROP CONSTRAINT FK_57B9FCBF82D40A1F');
        $this->addSql('ALTER TABLE test_flow DROP CONSTRAINT FK_57B9FCBF14B65AD6');
        $this->addSql('DROP TABLE flow_run');
        $this->addSql('DROP TABLE flow_step');
        $this->addSql('DROP TABLE step_result');
        $this->addSql('DROP TABLE test_flow');
    }
}
