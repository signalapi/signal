<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Platform-wide settings table for values managed from the admin panel — first
 * user: the Anthropic API key (sealed) and model for AI analysis, so enabling
 * AI no longer requires editing environment variables. Sealed settings keep the
 * ciphertext in value_encrypted and only a display hint in value.
 */
final class Version20260908070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform settings (admin-managed key/values, sealed secrets)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE platform_setting (
            id UUID NOT NULL,
            name VARCHAR(100) NOT NULL,
            value TEXT DEFAULT NULL,
            value_encrypted TEXT DEFAULT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PLATFORM_SETTING_NAME ON platform_setting (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE platform_setting');
    }
}
