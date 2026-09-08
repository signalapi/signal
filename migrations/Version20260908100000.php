<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Flaky-test quarantine: a flow whose recent runs keep flipping status is
 * quarantined automatically — its failures stop turning suite batches red and
 * are reported separately until the flow stabilises (or someone releases it).
 */
final class Version20260908100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quarantine fields on test_flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE test_flow ADD quarantined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE test_flow ADD quarantine_note VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE test_flow DROP quarantined_at');
        $this->addSql('ALTER TABLE test_flow DROP quarantine_note');
    }
}
