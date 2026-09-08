<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification rules can now ask for Claude's root-cause analysis to be
 * attached to failure messages. The flag lives on the rule so one channel can
 * get plain results while another gets the analysed version.
 */
final class Version20260908080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ai_analysis flag on notification rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_subscription ADD ai_analysis BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_subscription DROP ai_analysis');
    }
}
