<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260731084000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE schedule (id UUID NOT NULL, name VARCHAR(150) NOT NULL, enabled BOOLEAN NOT NULL, timezone VARCHAR(64) NOT NULL, rules JSON NOT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, flow_id UUID DEFAULT NULL, flow_group_id UUID DEFAULT NULL, environment_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5A3811FB7EB60D1B ON schedule (flow_id)');
        $this->addSql('CREATE INDEX IDX_5A3811FB6D1E161E ON schedule (flow_group_id)');
        $this->addSql('CREATE INDEX IDX_5A3811FB903E3A94 ON schedule (environment_id)');
        $this->addSql('CREATE INDEX idx_schedule_workspace ON schedule (workspace_id)');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_5A3811FB82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_5A3811FB7EB60D1B FOREIGN KEY (flow_id) REFERENCES test_flow (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_5A3811FB6D1E161E FOREIGN KEY (flow_group_id) REFERENCES flow_group (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_5A3811FB903E3A94 FOREIGN KEY (environment_id) REFERENCES environment (id) ON DELETE SET NULL NOT DEFERRABLE');

        // Carry the per-flow cron schedules over as one schedule each, using the
        // compiler's raw-cron rule mode so no expression is reinterpreted.
        $this->addSql(<<<'SQL'
            INSERT INTO schedule (id, workspace_id, flow_id, environment_id, name, enabled, timezone, rules, last_run_at, created_at)
            SELECT gen_random_uuid(), f.workspace_id, f.id, f.default_environment_id,
                   f.name, f.schedule_enabled, 'Europe/Istanbul',
                   json_build_array(json_build_object('mode', 'cron', 'expr', f.cron_expression, 'days', json_build_array(), 'monthDays', json_build_array()))::json,
                   f.last_scheduled_run_at, now()
              FROM test_flow f
             WHERE f.cron_expression IS NOT NULL AND f.cron_expression <> ''
            SQL);

        $this->addSql('ALTER TABLE test_flow DROP COLUMN schedule_enabled');
        $this->addSql('ALTER TABLE test_flow DROP COLUMN cron_expression');
        $this->addSql('ALTER TABLE test_flow DROP COLUMN last_scheduled_run_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE test_flow ADD schedule_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE test_flow ADD cron_expression VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE test_flow ADD last_scheduled_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE test_flow f SET schedule_enabled = s.enabled,
                                   cron_expression  = s.rules->0->>'expr',
                                   last_scheduled_run_at = s.last_run_at
              FROM schedule s
             WHERE s.flow_id = f.id AND s.rules->0->>'mode' = 'cron'
            SQL);

        $this->addSql('ALTER TABLE schedule DROP CONSTRAINT FK_5A3811FB82D40A1F');
        $this->addSql('ALTER TABLE schedule DROP CONSTRAINT FK_5A3811FB7EB60D1B');
        $this->addSql('ALTER TABLE schedule DROP CONSTRAINT FK_5A3811FB6D1E161E');
        $this->addSql('ALTER TABLE schedule DROP CONSTRAINT FK_5A3811FB903E3A94');
        $this->addSql('DROP TABLE schedule');
    }
}
