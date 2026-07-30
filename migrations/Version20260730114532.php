<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730114532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'env_user_value: per-user personal overrides for environment variables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE env_user_value (id UUID NOT NULL, name VARCHAR(200) NOT NULL, value TEXT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, environment_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_11DE8881903E3A94 ON env_user_value (environment_id)');
        $this->addSql('CREATE INDEX IDX_11DE8881A76ED395 ON env_user_value (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_env_user_value ON env_user_value (environment_id, user_id, name)');
        $this->addSql('ALTER TABLE env_user_value ADD CONSTRAINT FK_11DE8881903E3A94 FOREIGN KEY (environment_id) REFERENCES environment (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE env_user_value ADD CONSTRAINT FK_11DE8881A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE env_user_value DROP CONSTRAINT FK_11DE8881903E3A94');
        $this->addSql('ALTER TABLE env_user_value DROP CONSTRAINT FK_11DE8881A76ED395');
        $this->addSql('DROP TABLE env_user_value');
    }
}
