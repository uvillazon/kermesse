<?php

declare(strict_types=1);

namespace App\Tests\Identity\Application\Command;

use App\Audit\Infrastructure\Doctrine\DoctrineAuditLogWriter;
use App\Identity\Application\Command\AccessMutationFailed;
use App\Identity\Application\Command\TransferOrganizationOwnershipCommand;
use App\Identity\Application\Command\TransferOrganizationOwnershipHandler;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Infrastructure\Doctrine\DoctrineAccessDirectory;
use App\Identity\Infrastructure\Doctrine\DoctrineOrganizationOwnershipWriter;
use App\Shared\Infrastructure\Doctrine\TransactionalExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class TransferOrganizationOwnershipHandlerIntegrationTest extends TestCase
{
    private const CURRENT_OWNER_ID = '81000000-0000-4000-8000-000000000001';
    private const NEW_OWNER_ID = '81000000-0000-4000-8000-000000000002';
    private const ORGANIZATION_ID = '82000000-0000-4000-8000-000000000001';
    private const CURRENT_MEMBERSHIP_ID = '83000000-0000-4000-8000-000000000001';
    private const NEW_MEMBERSHIP_ID = '83000000-0000-4000-8000-000000000002';
    private const AUDIT_ID = '84000000-0000-4000-8000-000000000001';
    private const OWNER_ROLE_ID = '10000000-0000-4000-8000-000000000001';
    private const ADMIN_ROLE_ID = '10000000-0000-4000-8000-000000000002';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración contra MariaDB.');
        }

        $parts = parse_url($url);
        self::assertIsArray($parts);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 3306,
            'user' => $parts['user'] ?? null,
            'password' => $parts['pass'] ?? null,
            'dbname' => ltrim($parts['path'] ?? '', '/'),
            'charset' => 'utf8mb4',
            'serverVersion' => getenv('DATABASE_SERVER_VERSION') ?: '10.4.18-MariaDB',
        ]);
        $this->connection->setNestTransactionsWithSavepoints(true);
        $this->connection->beginTransaction();
        $this->insertFixture();
    }

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection?->close();
    }

    public function testTransfersOwnershipAndDemotesPreviousOwnerAtomically(): void
    {
        $membershipId = $this->handler()->handle($this->command());

        self::assertSame(self::NEW_MEMBERSHIP_ID, $membershipId);
        self::assertSame(OrganizationAccessType::ADMINISTRATOR->value, $this->accessType(self::CURRENT_MEMBERSHIP_ID));
        self::assertSame(OrganizationAccessType::OWNER->value, $this->accessType(self::NEW_MEMBERSHIP_ID));
        self::assertSame('ORG_ADMIN', $this->roleCode(self::CURRENT_MEMBERSHIP_ID));
        self::assertSame('ORG_OWNER', $this->roleCode(self::NEW_MEMBERSHIP_ID));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM organization_memberships
             WHERE organization_id = ? AND status = ? AND access_type = ?',
            [self::ORGANIZATION_ID, MembershipStatus::ACTIVE->value, OrganizationAccessType::OWNER->value],
        ));
        self::assertSame('identity.organization_ownership_transferred', $this->connection()->fetchOne(
            'SELECT action FROM audit_logs WHERE id = ?',
            [self::AUDIT_ID],
        ));
    }

    public function testRollsBackTransferWhenAuditCannotBeWritten(): void
    {
        $this->insertConflictingAudit();

        try {
            $this->handler()->handle($this->command());
            self::fail('Expected duplicate audit identifier to fail.');
        } catch (\Throwable) {
            self::assertSame(OrganizationAccessType::OWNER->value, $this->accessType(self::CURRENT_MEMBERSHIP_ID));
            self::assertSame(OrganizationAccessType::ADMINISTRATOR->value, $this->accessType(self::NEW_MEMBERSHIP_ID));
            self::assertSame('ORG_OWNER', $this->roleCode(self::CURRENT_MEMBERSHIP_ID));
            self::assertSame('ORG_ADMIN', $this->roleCode(self::NEW_MEMBERSHIP_ID));
        }
    }

    public function testAdministratorCannotTransferOwnership(): void
    {
        $this->connection()->update('organization_memberships', [
            'access_type' => OrganizationAccessType::ADMINISTRATOR->value,
        ], ['id' => self::CURRENT_MEMBERSHIP_ID]);

        $this->expectException(AccessMutationFailed::class);
        $this->expectExceptionMessage('Only the active organization owner');
        $this->handler()->handle($this->command());
    }

    private function handler(): TransferOrganizationOwnershipHandler
    {
        $connection = $this->connection();

        return new TransferOrganizationOwnershipHandler(
            new TransactionalExecutor($connection),
            new DoctrineOrganizationOwnershipWriter($connection),
            new DoctrineAccessDirectory($connection),
            new DoctrineAuditLogWriter($connection),
        );
    }

    private function command(): TransferOrganizationOwnershipCommand
    {
        return new TransferOrganizationOwnershipCommand(
            self::CURRENT_OWNER_ID,
            self::NEW_OWNER_ID,
            self::ORGANIZATION_ID,
            OrganizationAccessType::ADMINISTRATOR,
            self::AUDIT_ID,
            new \DateTimeImmutable('2026-10-30 14:00:00'),
            'ownership-transfer-request',
        );
    }

    private function insertFixture(): void
    {
        $connection = $this->connection();
        $now = '2026-10-30 13:00:00';
        foreach ([self::CURRENT_OWNER_ID, self::NEW_OWNER_ID] as $index => $userId) {
            $connection->insert('users', [
                'id' => $userId,
                'email' => 'ownership-'.$index.'@example.test',
                'email_normalized' => 'ownership-'.$index.'@example.test',
                'display_name' => 'Ownership User '.$index,
                'status' => IdentityStatus::ACTIVE->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $connection->insert('organizations', [
            'id' => self::ORGANIZATION_ID,
            'name' => 'Ownership Organization',
            'slug' => 'ownership-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            [self::CURRENT_OWNER_ID, self::CURRENT_MEMBERSHIP_ID, OrganizationAccessType::OWNER, self::OWNER_ROLE_ID],
            [self::NEW_OWNER_ID, self::NEW_MEMBERSHIP_ID, OrganizationAccessType::ADMINISTRATOR, self::ADMIN_ROLE_ID],
        ] as [$userId, $membershipId, $accessType, $roleId]) {
            $connection->insert('organization_memberships', [
                'id' => $membershipId,
                'organization_id' => self::ORGANIZATION_ID,
                'user_id' => $userId,
                'access_type' => $accessType->value,
                'status' => MembershipStatus::ACTIVE->value,
                'valid_from' => null,
                'valid_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $connection->insert('organization_membership_roles', [
                'organization_membership_id' => $membershipId,
                'role_id' => $roleId,
                'granted_by' => self::CURRENT_OWNER_ID,
                'granted_at' => $now,
            ]);
        }
    }

    private function insertConflictingAudit(): void
    {
        $this->connection()->insert('audit_logs', [
            'id' => self::AUDIT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'event_id' => null,
            'actor_user_id' => self::CURRENT_OWNER_ID,
            'action' => 'fixture',
            'aggregate_type' => 'fixture',
            'aggregate_id' => null,
            'request_id' => null,
            'metadata_json' => null,
            'created_at' => '2026-10-30 13:00:00',
        ]);
    }

    private function accessType(string $membershipId): string
    {
        return (string) $this->connection()->fetchOne(
            'SELECT access_type FROM organization_memberships WHERE id = ?',
            [$membershipId],
        );
    }

    private function roleCode(string $membershipId): string
    {
        return (string) $this->connection()->fetchOne(
            'SELECT r.code FROM organization_membership_roles mr INNER JOIN roles r ON r.id = mr.role_id
             WHERE mr.organization_membership_id = ?',
            [$membershipId],
        );
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
