<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730125124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'uniq_cookie now includes user_id: each person keeps their own jar entry per name';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_cookie');
        $this->addSql('CREATE UNIQUE INDEX uniq_cookie ON cookie (workspace_id, user_id, domain, path, name)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_cookie');
        $this->addSql('CREATE UNIQUE INDEX uniq_cookie ON cookie (workspace_id, domain, path, name)');
    }
}
