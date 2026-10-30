<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el outbox transaccional de notificaciones sin almacenar tokens de invitación en texto plano.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('notification_deliveries');
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $table->addColumn('event_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $table->addColumn('aggregate_type', Types::STRING, ['length' => 64]);
        $table->addColumn('aggregate_id', Types::STRING, ['length' => 36]);
        $table->addColumn('channel', Types::STRING, ['length' => 20]);
        $table->addColumn('recipient', Types::STRING, ['length' => 254]);
        $table->addColumn('template', Types::STRING, ['length' => 80]);
        $table->addColumn('payload_json', Types::JSON);
        $table->addColumn('token_key_version', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('status', Types::STRING, ['length' => 20]);
        $table->addColumn('attempts', Types::INTEGER, ['default' => 0]);
        $table->addColumn('provider_reference', Types::STRING, ['length' => 160, 'notnull' => false]);
        $table->addColumn('next_attempt_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['aggregate_type', 'aggregate_id', 'channel', 'template'],
            'uniq_notification_aggregate_channel_template',
        );
        $table->addIndex(['status', 'next_attempt_at'], 'idx_notification_pending');
        $table->addIndex(['organization_id', 'created_at'], 'idx_notification_org_created');
        $table->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_org');
        $table->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_event');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('notification_deliveries');
    }
}
