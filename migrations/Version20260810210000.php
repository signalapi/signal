<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin / merchant separation: platform operators move to their own
 * admin_user table. Existing ROLE_SUPER_ADMIN rows are copied over
 * (password hashes are portable) and the role is stripped from the
 * merchant user table, so the two identity sets no longer overlap.
 */
final class Version20260810210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split admin identities into their own admin_user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_user (
            id UUID NOT NULL,
            email VARCHAR(180) NOT NULL,
            name VARCHAR(120) NOT NULL,
            password VARCHAR(255) NOT NULL,
            active BOOLEAN NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_user_email ON admin_user (email)');

        // Carry existing super admins over, then retire the merchant-side row:
        // the role is stripped and the row deactivated, so operator credentials
        // stop working on /login entirely (rows are kept for FK integrity).
        $this->addSql("INSERT INTO admin_user (id, email, name, password, active, created_at)
            SELECT id, email, name, password, active, created_at FROM \"user\"
            WHERE CAST(roles AS TEXT) LIKE '%ROLE_SUPER_ADMIN%'");
        $this->addSql("UPDATE \"user\" SET roles = (roles::jsonb - 'ROLE_SUPER_ADMIN')::json, active = false
            WHERE CAST(roles AS TEXT) LIKE '%ROLE_SUPER_ADMIN%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE \"user\" u SET roles = (u.roles::jsonb || '[\"ROLE_SUPER_ADMIN\"]'::jsonb)::json, active = true
            FROM admin_user a WHERE a.id = u.id");
        $this->addSql('DROP TABLE admin_user');
    }
}
