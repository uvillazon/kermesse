<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030011000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea puestos y catálogo de productos por evento con precio, stock y control de versión.';
    }

    public function up(Schema $schema): void
    {
        $stands = $schema->createTable('stands');
        $stands->addColumn('id', Types::STRING, ['length' => 36]);
        $stands->addColumn('event_id', Types::STRING, ['length' => 36]);
        $stands->addColumn('name', Types::STRING, ['length' => 160]);
        $stands->addColumn('manager_name', Types::STRING, ['length' => 160]);
        $stands->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $stands->setPrimaryKey(['id']);
        $stands->addIndex(['event_id'], 'idx_stands_event');
        $stands->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_stands_event');

        $products = $schema->createTable('products');
        $products->addColumn('id', Types::STRING, ['length' => 36]);
        $products->addColumn('event_id', Types::STRING, ['length' => 36]);
        $products->addColumn('stand_id', Types::STRING, ['length' => 36]);
        $products->addColumn('code', Types::STRING, ['length' => 64]);
        $products->addColumn('name', Types::STRING, ['length' => 160]);
        $products->addColumn('description', Types::TEXT, ['notnull' => false]);
        $products->addColumn('unit_price_minor', Types::INTEGER);
        $products->addColumn('currency', Types::STRING, ['length' => 3, 'default' => 'BOB']);
        $products->addColumn('stock', Types::INTEGER);
        $products->addColumn('sold', Types::INTEGER, ['default' => 0]);
        $products->addColumn('active', Types::BOOLEAN, ['default' => true]);
        $products->addColumn('version', Types::INTEGER, ['default' => 1]);
        $products->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $products->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $products->setPrimaryKey(['id']);
        $products->addUniqueIndex(['event_id', 'code'], 'uniq_products_event_code');
        $products->addIndex(['event_id', 'active'], 'idx_products_event_active');
        $products->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_products_event');
        $products->addForeignKeyConstraint('stands', ['stand_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_products_stand');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('products');
        $schema->dropTable('stands');
    }
}
