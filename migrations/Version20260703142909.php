<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703142909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE flow_group_item (id UUID NOT NULL, position INT NOT NULL, flow_group_id UUID NOT NULL, flow_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_56E3D4186D1E161E ON flow_group_item (flow_group_id)');
        $this->addSql('CREATE INDEX IDX_56E3D4187EB60D1B ON flow_group_item (flow_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_flow ON flow_group_item (flow_group_id, flow_id)');
        $this->addSql('ALTER TABLE flow_group_item ADD CONSTRAINT FK_56E3D4186D1E161E FOREIGN KEY (flow_group_id) REFERENCES flow_group (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE flow_group_item ADD CONSTRAINT FK_56E3D4187EB60D1B FOREIGN KEY (flow_id) REFERENCES test_flow (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Preserve existing single-suite memberships in the new join table.
        $this->addSql('INSERT INTO flow_group_item (id, flow_group_id, flow_id, position) SELECT gen_random_uuid(), flow_group_id, id, group_position FROM test_flow WHERE flow_group_id IS NOT NULL');
        $this->addSql('ALTER TABLE test_flow DROP CONSTRAINT fk_57b9fcbf6d1e161e');
        $this->addSql('DROP INDEX idx_57b9fcbf6d1e161e');
        $this->addSql('ALTER TABLE test_flow DROP group_position');
        $this->addSql('ALTER TABLE test_flow DROP flow_group_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_group_item DROP CONSTRAINT FK_56E3D4186D1E161E');
        $this->addSql('ALTER TABLE flow_group_item DROP CONSTRAINT FK_56E3D4187EB60D1B');
        $this->addSql('DROP TABLE flow_group_item');
        $this->addSql('ALTER TABLE test_flow ADD group_position INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE test_flow ADD flow_group_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE test_flow ADD CONSTRAINT fk_57b9fcbf6d1e161e FOREIGN KEY (flow_group_id) REFERENCES flow_group (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_57b9fcbf6d1e161e ON test_flow (flow_group_id)');
    }
}
