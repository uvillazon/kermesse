<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010100 extends AbstractMigration
{
    private const ROLES = [
        'ORG_OWNER' => ['10000000-0000-4000-8000-000000000001', 'Responsable principal', 'organization'],
        'ORG_ADMIN' => ['10000000-0000-4000-8000-000000000002', 'Administrador de organización', 'organization'],
        'EVENT_MANAGER' => ['10000000-0000-4000-8000-000000000003', 'Responsable de evento', 'event'],
        'FINANCE_REVIEWER' => ['10000000-0000-4000-8000-000000000004', 'Revisor financiero', 'event'],
        'SALES_OPERATOR' => ['10000000-0000-4000-8000-000000000005', 'Operador de ventas', 'event'],
        'PAYMENT_REVIEWER' => ['10000000-0000-4000-8000-000000000006', 'Verificador de pagos', 'event'],
        'TICKET_OPERATOR' => ['10000000-0000-4000-8000-000000000007', 'Operador de tickets', 'event'],
        'REPORT_VIEWER' => ['10000000-0000-4000-8000-000000000008', 'Consultor de reportes', 'event'],
        'HISTORICAL_VIEWER' => ['10000000-0000-4000-8000-000000000009', 'Responsable histórico', 'event'],
    ];

    private const PERMISSIONS = [
        'org.read' => ['20000000-0000-4000-8000-000000000001', 'Consultar organización y eventos visibles'],
        'org.manage_members' => ['20000000-0000-4000-8000-000000000002', 'Gestionar responsables, invitaciones y roles'],
        'event.read' => ['20000000-0000-4000-8000-000000000003', 'Consultar configuración y estado del evento'],
        'event.manage' => ['20000000-0000-4000-8000-000000000004', 'Modificar configuración operativa del evento'],
        'catalog.manage' => ['20000000-0000-4000-8000-000000000005', 'Gestionar catálogo, precios y disponibilidad'],
        'course.manage' => ['20000000-0000-4000-8000-000000000006', 'Gestionar cursos, grupos y asignaciones'],
        'expense.write' => ['20000000-0000-4000-8000-000000000007', 'Registrar, modificar o anular gastos'],
        'sale.write' => ['20000000-0000-4000-8000-000000000008', 'Registrar ventas autorizadas'],
        'payment.review' => ['20000000-0000-4000-8000-000000000009', 'Revisar y cambiar estados de pagos'],
        'ticket.read' => ['20000000-0000-4000-8000-000000000010', 'Consultar tickets autorizados'],
        'ticket.redeem' => ['20000000-0000-4000-8000-000000000011', 'Validar y canjear tickets'],
        'report.read' => ['20000000-0000-4000-8000-000000000012', 'Consultar reportes autorizados'],
        'report.export' => ['20000000-0000-4000-8000-000000000013', 'Exportar reportes autorizados'],
        'balance.read' => ['20000000-0000-4000-8000-000000000014', 'Consultar balance autorizado'],
        'event.close' => ['20000000-0000-4000-8000-000000000015', 'Cerrar definitivamente un evento'],
        'audit.read' => ['20000000-0000-4000-8000-000000000016', 'Consultar auditoría autorizada'],
    ];

    private const ROLE_PERMISSIONS = [
        'ORG_OWNER' => ['org.read', 'org.manage_members'],
        'ORG_ADMIN' => ['org.read', 'org.manage_members'],
        'EVENT_MANAGER' => ['event.read', 'event.manage', 'catalog.manage', 'course.manage', 'ticket.read', 'report.read'],
        'FINANCE_REVIEWER' => ['event.read', 'payment.review', 'report.read', 'report.export', 'balance.read'],
        'SALES_OPERATOR' => ['event.read', 'sale.write'],
        'PAYMENT_REVIEWER' => ['event.read', 'payment.review'],
        'TICKET_OPERATOR' => ['event.read', 'ticket.read', 'ticket.redeem'],
        'REPORT_VIEWER' => ['event.read', 'report.read', 'report.export'],
        'HISTORICAL_VIEWER' => ['event.read', 'ticket.read', 'report.read', 'report.export', 'balance.read', 'audit.read'],
    ];

    public function getDescription(): string
    {
        return 'Carga el catálogo inicial de roles y permisos sin asignar event.close.';
    }

    public function up(Schema $schema): void
    {
        $now = '2026-10-30 00:00:00';

        foreach (self::ROLES as $code => [$id, $name, $scope]) {
            $this->addSql(
                'INSERT INTO roles (id, code, name, scope, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, $code, $name, $scope, $now],
            );
        }

        foreach (self::PERMISSIONS as $code => [$id, $description]) {
            $this->addSql(
                'INSERT INTO permissions (id, code, description) VALUES (?, ?, ?)',
                [$id, $code, $description],
            );
        }

        foreach (self::ROLE_PERMISSIONS as $roleCode => $permissionCodes) {
            $roleId = self::ROLES[$roleCode][0];
            foreach ($permissionCodes as $permissionCode) {
                $this->addSql(
                    'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$roleId, self::PERMISSIONS[$permissionCode][0]],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::ROLES) as [$id]) {
            $this->addSql('DELETE FROM roles WHERE id = ?', [$id]);
        }

        foreach (array_reverse(self::PERMISSIONS) as [$id]) {
            $this->addSql('DELETE FROM permissions WHERE id = ?', [$id]);
        }
    }
}
