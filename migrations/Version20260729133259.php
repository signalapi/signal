<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the legacy single-merchant link on user; membership now lives solely in
 * merchant_member (backfilled by the previous migration). Also strips the retired
 * ROLE_MERCHANT/ROLE_MERCHANT_ADMIN literals from user.roles — merchant authority
 * comes from membership rows, only ROLE_SUPER_ADMIN remains meaningful there.
 */
final class Version20260729133259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop user.merchant_id; strip retired merchant roles from user.roles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT fk_8d93d6496796d554');
        $this->addSql('DROP INDEX idx_8d93d6496796d554');
        $this->addSql('ALTER TABLE "user" DROP merchant_id');
        $this->addSql(<<<'SQL'
            UPDATE "user" SET roles = COALESCE(
                (SELECT jsonb_agg(r) FROM jsonb_array_elements_text(roles::jsonb) AS r
                 WHERE r NOT IN ('ROLE_MERCHANT', 'ROLE_MERCHANT_ADMIN')),
                '[]'::jsonb
            )::json
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD merchant_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT fk_8d93d6496796d554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_8d93d6496796d554 ON "user" (merchant_id)');
    }
}
