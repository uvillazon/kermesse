<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el registro persistente y concurrente de claves de idempotencia.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('idempotency_keys');
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $table->addColumn('event_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $table->addColumn('user_id', Types::STRING, ['length' => 36]);
        $table->addColumn('idempotency_key', Types::STRING, ['length' => 128]);
        $table->addColumn('method', Types::STRING, ['length' => 12]);
        $table->addColumn('route', Types::STRING, ['length' => 160]);
        $table->addColumn('request_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('state', Types::STRING, ['length' => 20]);
        $table->addColumn('status_code', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('response_json', Types::JSON, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['organization_id', 'user_id', 'idempotency_key', 'method', 'route'],
            'uniq_idempotency_scope',
        );
        $table->addIndex(['state', 'expires_at'], 'idx_idempotency_state_expiry');
        $table->addIndex(['event_id', 'created_at'], 'idx_idempotency_event_created');
        $table->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idempotency_org');
        $table->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idempotency_event');
        $table->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idempotency_user');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('idempotency_keys');
    }
}
