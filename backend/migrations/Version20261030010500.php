<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Amplía la ruta idempotente para recursos anidados de organización, evento y usuario.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('idempotency_keys')->modifyColumn('route', ['length' => 255]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('idempotency_keys')->modifyColumn('route', ['length' => 160]);
    }
}
