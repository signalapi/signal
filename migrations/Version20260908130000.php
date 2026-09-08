<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opt-in parallel suites: a suite marked run_parallel fans its flows out as one
 * message per flow, so several workers chew on the batch at once. Default stays
 * sequential — existing suites may rely on flow order.
 */
final class Version20260908130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'run_parallel flag on flow_group';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_group ADD run_parallel BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_group DROP run_parallel');
    }
}
