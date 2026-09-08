<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Strict contract mode on flows: response-shape drift, until now informational,
 * can fail the step. The baseline stays per-step (response_shape) and is reset
 * from the step editor after an intended API change.
 */
final class Version20260908110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'contract_strict flag on test_flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE test_flow ADD contract_strict BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE test_flow DROP contract_strict');
    }
}
