<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Password reset: the user row carries only the sha256 of the outstanding
 * reset token and its expiry — the token itself lives in the e-mail alone.
 */
final class Version20260908150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password-reset token hash on "user"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD reset_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD reset_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP reset_token_hash');
        $this->addSql('ALTER TABLE "user" DROP reset_token_expires_at');
    }
}
