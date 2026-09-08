<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Public status badges for suites: a random token on flow_group lets
 * /badge/{token}.svg serve the suite's latest outcome without a login —
 * README-safe, revocable, and readable as exactly one bit of state.
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'badge_token on flow_group';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flow_group ADD badge_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FLOW_GROUP_BADGE_TOKEN ON flow_group (badge_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_FLOW_GROUP_BADGE_TOKEN');
        $this->addSql('ALTER TABLE flow_group DROP badge_token');
    }
}
