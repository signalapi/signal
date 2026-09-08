<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Digest schedules: a schedule can now send the workspace digest (pass rate,
 * broken/flaky/quarantined tests, optional Claude commentary) to notification
 * destinations instead of running a flow — cron for reporting, not testing.
 */
final class Version20260908170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'kind column on schedule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE schedule ADD kind VARCHAR(12) DEFAULT 'run' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP kind');
    }
}
