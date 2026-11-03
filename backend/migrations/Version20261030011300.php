<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030011300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea asignaciones de productos y entregas por curso.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('course_allocations');
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $table->addColumn('event_id', Types::STRING, ['length' => 36]);
        $table->addColumn('course_name', Types::STRING, ['length' => 120]);
        $table->addColumn('delegate_name', Types::STRING, ['length' => 160]);
        $table->addColumn('product_id', Types::STRING, ['length' => 36]);
        $table->addColumn('product_name_snapshot', Types::STRING, ['length' => 160]);
        $table->addColumn('stand_name_snapshot', Types::STRING, ['length' => 160]);
        $table->addColumn('assigned_quantity', Types::INTEGER);
        $table->addColumn('delivered_quantity', Types::INTEGER, ['default' => 0]);
        $table->addColumn('returned_quantity', Types::INTEGER, ['default' => 0]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['organization_id', 'event_id', 'course_name'], 'idx_course_allocations_scope');
        $table->addIndex(['product_id'], 'idx_course_allocations_product');
        $table->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_course_allocations_org');
        $table->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_course_allocations_event');
        $table->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_course_allocations_product');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('course_allocations');
    }
}
