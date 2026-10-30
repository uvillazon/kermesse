<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade una concesión recuperable para procesar concurrentemente el outbox de notificaciones.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('notification_deliveries');
        $table->addColumn('lock_token', Types::STRING, ['length' => 36, 'notnull' => false]);
        $table->addColumn('locked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addIndex(['status', 'locked_at'], 'idx_notification_processing_lease');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('notification_deliveries');
        $table->dropIndex('idx_notification_processing_lease');
        $table->dropColumn('lock_token');
        $table->dropColumn('locked_at');
    }
}
