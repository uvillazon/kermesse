<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030011200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea tabla de gastos del evento (expenses) para reportes y balance financiero.';
    }

    public function up(Schema $schema): void
    {
        $expenses = $schema->createTable('expenses');
        $expenses->addColumn('id', Types::STRING, ['length' => 36]);
        $expenses->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $expenses->addColumn('event_id', Types::STRING, ['length' => 36]);
        $expenses->addColumn('recorded_by_user_id', Types::STRING, ['length' => 36]);
        $expenses->addColumn('concept', Types::STRING, ['length' => 200]);
        $expenses->addColumn('category', Types::STRING, ['length' => 64]);
        $expenses->addColumn('amount_minor', Types::INTEGER);
        $expenses->addColumn('currency', Types::STRING, ['length' => 3, 'default' => 'BOB']);
        $expenses->addColumn('notes', Types::TEXT, ['notnull' => false]);
        $expenses->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $expenses->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $expenses->setPrimaryKey(['id']);
        $expenses->addIndex(['organization_id', 'event_id', 'occurred_at'], 'idx_expenses_org_event_date');
        $expenses->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_expenses_org');
        $expenses->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_expenses_event');
        $expenses->addForeignKeyConstraint('users', ['recorded_by_user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_expenses_user');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('expenses');
    }
}
