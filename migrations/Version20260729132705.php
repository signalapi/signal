<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Membership foundation: merchant_member / workspace_member / invitation tables,
 * user scoping columns on cookie and response_history, plus backfill from the
 * legacy single `user.merchant_id` link (dropped in a follow-up migration).
 */
final class Version20260729132705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Membership tables (merchant/workspace/invitation) + backfill; user scoping on cookie & response_history';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invitation (id UUID NOT NULL, email VARCHAR(180) NOT NULL, merchant_role VARCHAR(20) NOT NULL, workspace_grants JSON NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, merchant_id UUID NOT NULL, invited_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F11D61A2A7B4A7E3 ON invitation (invited_by_id)');
        $this->addSql('CREATE INDEX idx_invitation_merchant ON invitation (merchant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invitation_token ON invitation (token_hash)');
        $this->addSql('CREATE TABLE merchant_member (id UUID NOT NULL, role VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, merchant_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_98CBBD916796D554 ON merchant_member (merchant_id)');
        $this->addSql('CREATE INDEX IDX_98CBBD91A76ED395 ON merchant_member (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_merchant_member ON merchant_member (merchant_id, user_id)');
        $this->addSql('CREATE TABLE workspace_member (id UUID NOT NULL, role VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_40242BD082D40A1F ON workspace_member (workspace_id)');
        $this->addSql('CREATE INDEX IDX_40242BD0A76ED395 ON workspace_member (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_workspace_member ON workspace_member (workspace_id, user_id)');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A26796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE merchant_member ADD CONSTRAINT FK_98CBBD916796D554 FOREIGN KEY (merchant_id) REFERENCES merchant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE merchant_member ADD CONSTRAINT FK_98CBBD91A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workspace_member ADD CONSTRAINT FK_40242BD082D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workspace_member ADD CONSTRAINT FK_40242BD0A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cookie ADD user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE cookie ADD CONSTRAINT FK_8AE0BA66A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8AE0BA66A76ED395 ON cookie (user_id)');
        $this->addSql('ALTER TABLE response_history ADD user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE response_history ADD CONSTRAINT FK_8C003635A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8C003635A76ED395 ON response_history (user_id)');

        // ---- Backfill ----------------------------------------------------
        // Every user linked via the legacy user.merchant_id becomes a member;
        // ROLE_MERCHANT_ADMIN maps to admin, the rest to member.
        $this->addSql(<<<'SQL'
            INSERT INTO merchant_member (id, merchant_id, user_id, role, created_at)
            SELECT gen_random_uuid(), u.merchant_id, u.id,
                   CASE WHEN jsonb_exists(u.roles::jsonb, 'ROLE_MERCHANT_ADMIN') THEN 'admin' ELSE 'member' END,
                   u.created_at
            FROM "user" u
            WHERE u.merchant_id IS NOT NULL
            SQL);
        // The oldest admin of each merchant becomes its owner...
        $this->addSql(<<<'SQL'
            UPDATE merchant_member SET role = 'owner'
            WHERE id IN (
                SELECT DISTINCT ON (m.merchant_id) m.id
                FROM merchant_member m
                JOIN "user" u ON u.id = m.user_id
                WHERE m.role = 'admin'
                ORDER BY m.merchant_id, u.created_at ASC, u.id ASC
            )
            SQL);
        // ...and a merchant with no admin at all falls back to its oldest member.
        $this->addSql(<<<'SQL'
            UPDATE merchant_member SET role = 'owner'
            WHERE id IN (
                SELECT DISTINCT ON (m.merchant_id) m.id
                FROM merchant_member m
                JOIN "user" u ON u.id = m.user_id
                WHERE NOT EXISTS (
                    SELECT 1 FROM merchant_member o
                    WHERE o.merchant_id = m.merchant_id AND o.role = 'owner'
                )
                ORDER BY m.merchant_id, u.created_at ASC, u.id ASC
            )
            SQL);
        // Today every merchant user sees every workspace; explicit editor rows
        // preserve that exact behavior once membership filtering turns on.
        $this->addSql(<<<'SQL'
            INSERT INTO workspace_member (id, workspace_id, user_id, role, created_at)
            SELECT gen_random_uuid(), w.id, u.id, 'editor', NOW()
            FROM workspace w
            JOIN "user" u ON u.merchant_id = w.merchant_id
            SQL);
        // Existing shared cookie jars become the merchant owner's personal jar.
        $this->addSql(<<<'SQL'
            UPDATE cookie c SET user_id = (
                SELECT mm.user_id
                FROM merchant_member mm
                JOIN workspace w ON w.merchant_id = mm.merchant_id
                WHERE w.id = c.workspace_id AND mm.role = 'owner'
                LIMIT 1
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A26796D554');
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2A7B4A7E3');
        $this->addSql('ALTER TABLE merchant_member DROP CONSTRAINT FK_98CBBD916796D554');
        $this->addSql('ALTER TABLE merchant_member DROP CONSTRAINT FK_98CBBD91A76ED395');
        $this->addSql('ALTER TABLE workspace_member DROP CONSTRAINT FK_40242BD082D40A1F');
        $this->addSql('ALTER TABLE workspace_member DROP CONSTRAINT FK_40242BD0A76ED395');
        $this->addSql('DROP TABLE invitation');
        $this->addSql('DROP TABLE merchant_member');
        $this->addSql('DROP TABLE workspace_member');
        $this->addSql('ALTER TABLE cookie DROP CONSTRAINT FK_8AE0BA66A76ED395');
        $this->addSql('DROP INDEX IDX_8AE0BA66A76ED395');
        $this->addSql('ALTER TABLE cookie DROP user_id');
        $this->addSql('ALTER TABLE response_history DROP CONSTRAINT FK_8C003635A76ED395');
        $this->addSql('DROP INDEX IDX_8C003635A76ED395');
        $this->addSql('ALTER TABLE response_history DROP user_id');
    }
}
