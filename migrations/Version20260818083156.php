<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Run-result notifications: destinations (Slack incoming webhook or any HTTP
 * endpoint), standing rules per workspace/flow/suite, and a delivery log so a
 * failed send is visible instead of silent. Runs carry the choice made at start
 * time (notify_override); schedules carry their own destination list.
 */
final class Version20260818083156 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification destinations, subscriptions and delivery log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_destination (
            id UUID NOT NULL,
            workspace_id UUID NOT NULL,
            created_by_id UUID DEFAULT NULL,
            name VARCHAR(120) NOT NULL,
            type VARCHAR(30) NOT NULL,
            url_encrypted TEXT NOT NULL,
            url_host VARCHAR(190) NOT NULL,
            secret_encrypted TEXT DEFAULT NULL,
            label VARCHAR(120) DEFAULT NULL,
            active BOOLEAN NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_EB5D0DEB82D40A1F ON notification_destination (workspace_id)');
        $this->addSql('CREATE INDEX IDX_EB5D0DEBB03A8386 ON notification_destination (created_by_id)');
        $this->addSql('ALTER TABLE notification_destination ADD CONSTRAINT FK_EB5D0DEB82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_destination ADD CONSTRAINT FK_EB5D0DEBB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE notification_subscription (
            id UUID NOT NULL,
            workspace_id UUID NOT NULL,
            destination_id UUID NOT NULL,
            scope_type VARCHAR(20) NOT NULL,
            scope_id UUID DEFAULT NULL,
            condition VARCHAR(20) NOT NULL,
            enabled BOOLEAN NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_A2C88EE682D40A1F ON notification_subscription (workspace_id)');
        $this->addSql('CREATE INDEX IDX_A2C88EE6816C6140 ON notification_subscription (destination_id)');
        $this->addSql('ALTER TABLE notification_subscription ADD CONSTRAINT FK_A2C88EE682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_subscription ADD CONSTRAINT FK_A2C88EE6816C6140 FOREIGN KEY (destination_id) REFERENCES notification_destination (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE notification_delivery (
            id UUID NOT NULL,
            workspace_id UUID NOT NULL,
            destination_id UUID DEFAULT NULL,
            destination_name VARCHAR(120) NOT NULL,
            event VARCHAR(30) NOT NULL,
            subject VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL,
            attempts INT NOT NULL,
            response_code INT DEFAULT NULL,
            error TEXT DEFAULT NULL,
            payload JSON NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX IDX_6291D4B582D40A1F ON notification_delivery (workspace_id)');
        $this->addSql('CREATE INDEX IDX_6291D4B5816C6140 ON notification_delivery (destination_id)');
        $this->addSql('CREATE INDEX idx_delivery_workspace_created ON notification_delivery (workspace_id, created_at)');
        $this->addSql('ALTER TABLE notification_delivery ADD CONSTRAINT FK_6291D4B582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_delivery ADD CONSTRAINT FK_6291D4B5816C6140 FOREIGN KEY (destination_id) REFERENCES notification_destination (id) ON DELETE SET NULL NOT DEFERRABLE');

        // Existing rows need a value, so the new NOT NULL columns land with a
        // default that is then dropped — the entities always write the value.
        $this->addSql("ALTER TABLE flow_group_run ADD trigger VARCHAR(20) NOT NULL DEFAULT 'manual'");
        $this->addSql('ALTER TABLE flow_group_run ALTER trigger DROP DEFAULT');
        $this->addSql('ALTER TABLE flow_group_run ADD notify_override JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE flow_run ADD notify_override JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE schedule ADD notify JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE schedule ALTER notify DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP notify');
        $this->addSql('ALTER TABLE flow_run DROP notify_override');
        $this->addSql('ALTER TABLE flow_group_run DROP notify_override');
        $this->addSql('ALTER TABLE flow_group_run DROP trigger');
        $this->addSql('DROP TABLE notification_delivery');
        $this->addSql('DROP TABLE notification_subscription');
        $this->addSql('DROP TABLE notification_destination');
    }
}
