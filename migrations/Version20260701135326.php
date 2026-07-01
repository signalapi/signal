<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701135326 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step ADD response_shape JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_step ADD contract_baseline_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Default so existing rows satisfy NOT NULL; drop the default afterwards
        // (the app always writes an explicit value).
        $this->addSql("ALTER TABLE step_result ADD shape_drift JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE step_result ALTER COLUMN shape_drift DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE flow_step DROP response_shape');
        $this->addSql('ALTER TABLE flow_step DROP contract_baseline_at');
        $this->addSql('ALTER TABLE step_result DROP shape_drift');
    }
}
