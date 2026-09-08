<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Value snapshots on HTTP steps — the Jest-snapshot idea for APIs: the first
 * successful run captures the (normalised, volatile-fields-masked) response,
 * later runs fail when any value changes, and resetting re-approves.
 */
final class Version20260908160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Snapshot fields on flow_step';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_step ADD snapshot_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE flow_step ADD snapshot_ignore TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_step ADD snapshot_value JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_step ADD snapshot_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_step DROP snapshot_enabled');
        $this->addSql('ALTER TABLE flow_step DROP snapshot_ignore');
        $this->addSql('ALTER TABLE flow_step DROP snapshot_value');
        $this->addSql('ALTER TABLE flow_step DROP snapshot_at');
    }
}
