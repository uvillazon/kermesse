<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Completa el tipo de acceso de las membresías organizacionales requerido por la política de autorización.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('organization_memberships')->addColumn('access_type', Types::STRING, [
            'length' => 32,
            'default' => 'collaborator',
        ]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('organization_memberships')->dropColumn('access_type');
    }
}
