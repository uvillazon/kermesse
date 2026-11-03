<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega credencial local (hash de contraseña) a usuarios; nullable hasta que cada cuenta defina su contraseña.';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        if (!$users->hasColumn('password_hash')) {
            // 255 cubre bcrypt y argon2id con margen para futuros algoritmos de password_hash().
            $users->addColumn('password_hash', Types::STRING, ['length' => 255, 'notnull' => false]);
        }
        if (!$users->hasColumn('password_updated_at')) {
            $users->addColumn('password_updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');
        if ($users->hasColumn('password_updated_at')) {
            $users->dropColumn('password_updated_at');
        }
        if ($users->hasColumn('password_hash')) {
            $users->dropColumn('password_hash');
        }
    }
}
