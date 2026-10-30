<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea contadores persistentes por minuto para limitar solicitudes API entre procesos.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('api_rate_limit_buckets');
        $table->addColumn('bucket_key', Types::STRING, ['length' => 64]);
        $table->addColumn('window_started_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('hit_count', Types::INTEGER, ['unsigned' => true]);
        $table->setPrimaryKey(['bucket_key']);
        $table->addIndex(['expires_at'], 'idx_rate_limit_expiry');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('api_rate_limit_buckets');
    }
}
