<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Infrastructure\Doctrine\DoctrineAccessDirectory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DoctrineAccessDirectoryIntegrationTest extends TestCase
{
    private const USER_ID = '31000000-0000-4000-8000-000000000001';
    private const ORGANIZATION_ID = '32000000-0000-4000-8000-000000000001';
    private const OTHER_ORGANIZATION_ID = '32000000-0000-4000-8000-000000000002';
    private const EVENT_ID = '33000000-0000-4000-8000-000000000001';
    private const MEMBERSHIP_ID = '34000000-0000-4000-8000-000000000001';
    private const EVENT_MEMBERSHIP_ID = '35000000-0000-4000-8000-000000000001';
    private const EVENT_MANAGER_ROLE_ID = '10000000-0000-4000-8000-000000000003';

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

    public function testReconstructsIdentityMembershipEventAndRoles(): void
    {
        $directory = new DoctrineAccessDirectory($this->connection());

        $identity = $directory->findIdentity(self::USER_ID);
        $membership = $directory->findOrganizationMembership(self::USER_ID, self::ORGANIZATION_ID);
        $event = $directory->findEvent(self::EVENT_ID);
        $assignment = $directory->findEventAssignment(self::USER_ID, self::ORGANIZATION_ID, self::EVENT_ID);

        self::assertNotNull($identity);
        self::assertSame(IdentityStatus::ACTIVE, $identity->status);
        self::assertNotNull($membership);
        self::assertSame(OrganizationAccessType::OWNER, $membership->accessType);
        self::assertNotNull($event);
        self::assertSame(EventStatus::OPEN, $event->status);
        self::assertNotNull($assignment);
        self::assertSame(EventAccessType::OPERATIONAL, $assignment->accessType);
        self::assertSame([EventRole::EVENT_MANAGER], $assignment->roles);
    }

    public function testDoesNotReturnAnEventAssignmentFromAnotherOrganization(): void
    {
        $directory = new DoctrineAccessDirectory($this->connection());

        self::assertNull($directory->findEventAssignment(
            self::USER_ID,
            self::OTHER_ORGANIZATION_ID,
            self::EVENT_ID,
        ));
    }

    private function insertFixture(): void
    {
        $now = '2026-10-30 12:00:00';
        $connection = $this->connection();
        $connection->insert('users', [
            'id' => self::USER_ID,
            'email' => 'integration@example.test',
            'email_normalized' => 'integration@example.test',
            'display_name' => 'Integration User',
            'status' => IdentityStatus::ACTIVE->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([self::ORGANIZATION_ID, self::OTHER_ORGANIZATION_ID] as $organizationId) {
            $connection->insert('organizations', [
                'id' => $organizationId,
                'name' => 'Integration Organization',
                'slug' => $organizationId,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $connection->insert('organization_memberships', [
            'id' => self::MEMBERSHIP_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'user_id' => self::USER_ID,
            'access_type' => OrganizationAccessType::OWNER->value,
            'status' => MembershipStatus::ACTIVE->value,
            'valid_from' => null,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('events', [
            'id' => self::EVENT_ID,
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Integration Event',
            'slug' => 'integration-event',
            'starts_at' => null,
            'ends_at' => null,
            'status' => EventStatus::OPEN->value,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('event_memberships', [
            'id' => self::EVENT_MEMBERSHIP_ID,
            'event_id' => self::EVENT_ID,
            'user_id' => self::USER_ID,
            'access_type' => EventAccessType::OPERATIONAL->value,
            'status' => MembershipStatus::ACTIVE->value,
            'valid_from' => null,
            'valid_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('event_membership_roles', [
            'event_membership_id' => self::EVENT_MEMBERSHIP_ID,
            'role_id' => self::EVENT_MANAGER_ROLE_ID,
            'granted_by' => null,
            'granted_at' => $now,
        ]);
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
