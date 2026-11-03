<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010900 extends AbstractMigration
{
    private const PERMISSION_ID = '20000000-0000-4000-8000-000000000017';
    private const EVENT_MANAGER_ROLE_ID = '10000000-0000-4000-8000-000000000003';
    private const SALES_OPERATOR_ROLE_ID = '10000000-0000-4000-8000-000000000005';

    public function getDescription(): string
    {
        return 'Añade catalog.read para que responsables y operadores consulten productos sin poder modificarlos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO permissions (id, code, description) VALUES (?, ?, ?)',
            [self::PERMISSION_ID, 'catalog.read', 'Consultar catálogo y disponibilidad autorizados'],
        );
        $this->addSql(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
            [self::EVENT_MANAGER_ROLE_ID, self::PERMISSION_ID],
        );
        $this->addSql(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
            [self::SALES_OPERATOR_ROLE_ID, self::PERMISSION_ID],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM role_permissions WHERE permission_id = ?', [self::PERMISSION_ID]);
        $this->addSql('DELETE FROM permissions WHERE id = ?', [self::PERMISSION_ID]);
    }
}
