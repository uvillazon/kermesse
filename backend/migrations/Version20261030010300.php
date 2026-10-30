<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade los roles explícitos requeridos por invitaciones de evento.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('access_invitations')->addColumn('roles_json', Types::JSON, [
            'notnull' => false,
        ]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('access_invitations')->dropColumn('roles_json');
    }
}
