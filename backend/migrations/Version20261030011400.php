<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030011400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garantiza el campo de vencimiento de reservas en instalaciones que ya registraron la versión 11300.';
    }

    public function up(Schema $schema): void
    {
        $sales = $schema->getTable('sales');
        if (!$sales->hasColumn('reservation_expires_at')) {
            $sales->addColumn('reservation_expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
        if (!$sales->hasIndex('idx_sales_reservation_expiry')) {
            $sales->addIndex(['status', 'reservation_expires_at'], 'idx_sales_reservation_expiry');
        }
    }

    public function down(Schema $schema): void
    {
        // La versión 11300 es propietaria del campo en instalaciones limpias.
    }
}
