<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Teardown steps: a step marked always-run still executes after the flow has
 * stopped, so a test that changes external state can put it back even when the
 * assertion in the middle failed. Without it, stopOnFailure leaves whatever the
 * setup touched exactly as the failing run left it.
 */
final class Version20260915110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'always_run column on flow_step';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_step ADD always_run BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_step DROP always_run');
    }
}
