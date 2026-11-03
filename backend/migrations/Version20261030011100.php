<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030011100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea tablas de ventas (sales, sale_items), pagos (payments) y tickets de canje (tickets, ticket_items, ticket_redemptions).';
    }

    public function up(Schema $schema): void
    {
        // 1. Sales
        $sales = $schema->createTable('sales');
        $sales->addColumn('id', Types::STRING, ['length' => 36]);
        $sales->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $sales->addColumn('event_id', Types::STRING, ['length' => 36]);
        $sales->addColumn('operator_user_id', Types::STRING, ['length' => 36]);
        $sales->addColumn('channel', Types::STRING, ['length' => 32, 'default' => 'operator']);
        $sales->addColumn('status', Types::STRING, ['length' => 32]);
        $sales->addColumn('buyer_email', Types::STRING, ['length' => 254]);
        $sales->addColumn('buyer_name', Types::STRING, ['length' => 160, 'notnull' => false]);
        $sales->addColumn('buyer_phone', Types::STRING, ['length' => 40, 'notnull' => false]);
        $sales->addColumn('subtotal_minor', Types::INTEGER);
        $sales->addColumn('discount_total_minor', Types::INTEGER, ['default' => 0]);
        $sales->addColumn('total_minor', Types::INTEGER);
        $sales->addColumn('currency', Types::STRING, ['length' => 3, 'default' => 'BOB']);
        $sales->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $sales->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $sales->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $sales->setPrimaryKey(['id']);
        $sales->addIndex(['organization_id', 'event_id', 'created_at'], 'idx_sales_org_event_created');
        $sales->addIndex(['event_id', 'status'], 'idx_sales_event_status');
        $sales->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_sales_org');
        $sales->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_sales_event');
        $sales->addForeignKeyConstraint('users', ['operator_user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_sales_operator');

        // 2. Sale Items
        $saleItems = $schema->createTable('sale_items');
        $saleItems->addColumn('id', Types::STRING, ['length' => 36]);
        $saleItems->addColumn('sale_id', Types::STRING, ['length' => 36]);
        $saleItems->addColumn('product_id', Types::STRING, ['length' => 36]);
        $saleItems->addColumn('product_code_snapshot', Types::STRING, ['length' => 64]);
        $saleItems->addColumn('product_name_snapshot', Types::STRING, ['length' => 160]);
        $saleItems->addColumn('unit_price_minor_snapshot', Types::INTEGER);
        $saleItems->addColumn('quantity', Types::INTEGER);
        $saleItems->addColumn('line_total_minor', Types::INTEGER);
        $saleItems->addColumn('version_snapshot', Types::INTEGER, ['default' => 1]);
        $saleItems->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $saleItems->setPrimaryKey(['id']);
        $saleItems->addIndex(['sale_id'], 'idx_sale_items_sale');
        $saleItems->addIndex(['product_id'], 'idx_sale_items_product');
        $saleItems->addForeignKeyConstraint('sales', ['sale_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_sale_items_sale');
        $saleItems->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_sale_items_product');

        // 3. Payments
        $payments = $schema->createTable('payments');
        $payments->addColumn('id', Types::STRING, ['length' => 36]);
        $payments->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $payments->addColumn('event_id', Types::STRING, ['length' => 36]);
        $payments->addColumn('sale_id', Types::STRING, ['length' => 36]);
        $payments->addColumn('method', Types::STRING, ['length' => 32]);
        $payments->addColumn('status', Types::STRING, ['length' => 32]);
        $payments->addColumn('amount_minor', Types::INTEGER);
        $payments->addColumn('currency', Types::STRING, ['length' => 3, 'default' => 'BOB']);
        $payments->addColumn('provider_reference', Types::STRING, ['length' => 128, 'notnull' => false]);
        $payments->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $payments->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $payments->setPrimaryKey(['id']);
        $payments->addIndex(['organization_id', 'event_id'], 'idx_payments_org_event');
        $payments->addIndex(['sale_id'], 'idx_payments_sale');
        $payments->addForeignKeyConstraint('sales', ['sale_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_payments_sale');

        // 4. Tickets
        $tickets = $schema->createTable('tickets');
        $tickets->addColumn('id', Types::STRING, ['length' => 36]);
        $tickets->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $tickets->addColumn('event_id', Types::STRING, ['length' => 36]);
        $tickets->addColumn('sale_id', Types::STRING, ['length' => 36]);
        $tickets->addColumn('ticket_number', Types::STRING, ['length' => 64]);
        $tickets->addColumn('qr_code', Types::STRING, ['length' => 128]);
        $tickets->addColumn('status', Types::STRING, ['length' => 32, 'default' => 'active']);
        $tickets->addColumn('buyer_email', Types::STRING, ['length' => 254]);
        $tickets->addColumn('buyer_name', Types::STRING, ['length' => 160, 'notnull' => false]);
        $tickets->addColumn('issued_at', Types::DATETIME_IMMUTABLE);
        $tickets->setPrimaryKey(['id']);
        $tickets->addUniqueIndex(['qr_code'], 'uniq_tickets_qr');
        $tickets->addIndex(['event_id', 'status'], 'idx_tickets_event_status');
        $tickets->addForeignKeyConstraint('sales', ['sale_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_tickets_sale');

        // 5. Ticket Items
        $ticketItems = $schema->createTable('ticket_items');
        $ticketItems->addColumn('id', Types::STRING, ['length' => 36]);
        $ticketItems->addColumn('ticket_id', Types::STRING, ['length' => 36]);
        $ticketItems->addColumn('sale_item_id', Types::STRING, ['length' => 36]);
        $ticketItems->addColumn('product_id', Types::STRING, ['length' => 36]);
        $ticketItems->addColumn('product_name_snapshot', Types::STRING, ['length' => 160]);
        $ticketItems->addColumn('quantity', Types::INTEGER);
        $ticketItems->addColumn('redeemed_quantity', Types::INTEGER, ['default' => 0]);
        $ticketItems->setPrimaryKey(['id']);
        $ticketItems->addIndex(['ticket_id'], 'idx_ticket_items_ticket');
        $ticketItems->addForeignKeyConstraint('tickets', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_ticket_items_ticket');
        $ticketItems->addForeignKeyConstraint('sale_items', ['sale_item_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_ticket_items_sale_item');

        // 6. Ticket Redemptions
        $ticketRedemptions = $schema->createTable('ticket_redemptions');
        $ticketRedemptions->addColumn('id', Types::STRING, ['length' => 36]);
        $ticketRedemptions->addColumn('ticket_id', Types::STRING, ['length' => 36]);
        $ticketRedemptions->addColumn('ticket_item_id', Types::STRING, ['length' => 36]);
        $ticketRedemptions->addColumn('redeemed_by_user_id', Types::STRING, ['length' => 36]);
        $ticketRedemptions->addColumn('quantity', Types::INTEGER);
        $ticketRedemptions->addColumn('redeemed_at', Types::DATETIME_IMMUTABLE);
        $ticketRedemptions->setPrimaryKey(['id']);
        $ticketRedemptions->addIndex(['ticket_id'], 'idx_redemptions_ticket');
        $ticketRedemptions->addForeignKeyConstraint('tickets', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_redemptions_ticket');
        $ticketRedemptions->addForeignKeyConstraint('ticket_items', ['ticket_item_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_redemptions_item');
        $ticketRedemptions->addForeignKeyConstraint('users', ['redeemed_by_user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_redemptions_user');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('ticket_redemptions');
        $schema->dropTable('ticket_items');
        $schema->dropTable('tickets');
        $schema->dropTable('payments');
        $schema->dropTable('sale_items');
        $schema->dropTable('sales');
    }
}
