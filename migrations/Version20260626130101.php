<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626130101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step ADD canvas_x INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE flow_step ADD canvas_y INT DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE test_flow ADD canvas_edges JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE test_flow ALTER COLUMN canvas_edges DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step DROP canvas_x');
        $this->addSql('ALTER TABLE flow_step DROP canvas_y');
        $this->addSql('ALTER TABLE test_flow DROP canvas_edges');
    }
}
