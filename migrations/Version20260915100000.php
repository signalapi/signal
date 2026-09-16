<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repeats on a data-driven run: the same dataset row can now be run K times in
 * one batch, so a flow whose result is not deterministic (an LLM reply, an
 * agent's tool choice) can be measured instead of guessed at. `iteration` stays
 * the row index; `repeat_index` says which attempt of that row this run is.
 */
final class Version20260915100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'repeat_index column on flow_run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_run ADD repeat_index INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_run DROP repeat_index');
    }
}
