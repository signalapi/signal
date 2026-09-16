<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Evaluations: a saved measurement of a flow whose result is not the same every
 * time — the flow, the rows to feed it, and how many times to repeat each one.
 *
 * Each execution keeps its own report (evaluation_run), with the headline
 * numbers as columns so a trend over ninety days is one query instead of
 * loading every step result of every run in every batch.
 */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'evaluation and evaluation_run tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE evaluation (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                flow_id UUID NOT NULL,
                environment_id UUID DEFAULT NULL,
                name VARCHAR(150) NOT NULL,
                description TEXT DEFAULT NULL,
                dataset JSON NOT NULL,
                repeats INT DEFAULT 1 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_evaluation_workspace ON evaluation (workspace_id)');
        $this->addSql('CREATE INDEX idx_evaluation_flow ON evaluation (flow_id)');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT fk_evaluation_workspace FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT fk_evaluation_flow FOREIGN KEY (flow_id) REFERENCES test_flow (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT fk_evaluation_environment FOREIGN KEY (environment_id) REFERENCES environment (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE evaluation_run (
                id UUID NOT NULL,
                evaluation_id UUID NOT NULL,
                batch_id VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                trigger VARCHAR(20) NOT NULL,
                environment_name VARCHAR(150) DEFAULT NULL,
                pass_rate DOUBLE PRECISION DEFAULT 0 NOT NULL,
                row_count INT DEFAULT 0 NOT NULL,
                repeats INT DEFAULT 0 NOT NULL,
                runs INT DEFAULT 0 NOT NULL,
                runs_passed INT DEFAULT 0 NOT NULL,
                stable_pass_rows INT DEFAULT 0 NOT NULL,
                flaky_rows INT DEFAULT 0 NOT NULL,
                stable_fail_rows INT DEFAULT 0 NOT NULL,
                report JSON DEFAULT NULL,
                error TEXT DEFAULT NULL,
                notify_override JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_evaluation_run_eval ON evaluation_run (evaluation_id, created_at)');
        $this->addSql('ALTER TABLE evaluation_run ADD CONSTRAINT fk_evaluation_run_eval FOREIGN KEY (evaluation_id) REFERENCES evaluation (id) ON DELETE CASCADE');

        // Schedules gain a third thing they can point at, alongside flow and suite.
        $this->addSql('ALTER TABLE schedule ADD evaluation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT fk_schedule_evaluation FOREIGN KEY (evaluation_id) REFERENCES evaluation (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_schedule_evaluation ON schedule (evaluation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP CONSTRAINT fk_schedule_evaluation');
        $this->addSql('DROP INDEX idx_schedule_evaluation');
        $this->addSql('ALTER TABLE schedule DROP evaluation_id');
        $this->addSql('DROP TABLE evaluation_run');
        $this->addSql('DROP TABLE evaluation');
    }
}
