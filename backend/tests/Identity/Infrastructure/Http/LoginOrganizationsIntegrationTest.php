<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El login devuelve las organizaciones vigentes del usuario para que el frontend
 * deje de usar un id de organización fijo. (La verificación de contraseñas se prueba
 * en LoginControllerIntegrationTest.)
 */
final class LoginOrganizationsIntegrationTest extends WebTestCase
{
    private const USER_ID = 'c1000000-0000-4000-8000-000000000001';
    private const ORG_ALFA = 'c2000000-0000-4000-8000-000000000001';
    private const ORG_BETA = 'c2000000-0000-4000-8000-000000000002';
    private const ORG_EXPIRED = 'c2000000-0000-4000-8000-000000000003';
    private const ORG_SUSPENDED = 'c2000000-0000-4000-8000-000000000004';
    private const ORG_PENDING = 'c2000000-0000-4000-8000-000000000005';
    private const EMAIL = 'login-orgs@example.test';

    private ?KernelBrowser $client = null;
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración HTTP contra MariaDB.');
        }

        putenv('DATABASE_URL='.$url);
        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
        $this->client = static::createClient();
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->removeFixture();
        $this->insertFixture();
    }

    protected function tearDown(): void
    {
        $this->removeFixture();
        $this->connection?->close();
        $this->client = null;
        parent::tearDown();
    }

    public function testReturnsOnlyCurrentActiveOrganizationsSortedByName(): void
    {
        $user = $this->login();

        self::assertSame([
            ['id' => self::ORG_ALFA, 'name' => 'Alfa Login Org', 'slug' => 'alfa-login-org', 'access' => 'owner'],
            ['id' => self::ORG_BETA, 'name' => 'Beta Login Org', 'slug' => 'beta-login-org', 'access' => 'collaborator'],
        ], $user['organizations']);
        // Compatibilidad: orgAccess sigue siendo el acceso en la primera organización.
        self::assertSame('owner', $user['orgAccess']);
    }

    public function testUserWithoutCurrentMembershipGetsEmptyListAndCollaboratorAccess(): void
    {
        $this->connection()->delete('organization_memberships', ['organization_id' => self::ORG_ALFA]);
        $this->connection()->delete('organization_memberships', ['organization_id' => self::ORG_BETA]);

        $user = $this->login();

        self::assertSame([], $user['organizations']);
        self::assertSame('collaborator', $user['orgAccess']);
    }

    /** @return array<string, mixed> */
    private function login(): array
    {
        $this->client()->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => self::EMAIL, 'password' => 'password123'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $content = $this->client()->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['token']);
        self::assertIsArray($decoded['user']);

        return $decoded['user'];
    }

    private function insertFixture(): void
    {
        $now = '2026-10-30 16:00:00';
        $this->connection()->insert('users', [
            'id' => self::USER_ID,
            'email' => self::EMAIL,
            'email_normalized' => self::EMAIL,
            'display_name' => 'Login Orgs User',
            'status' => 'active',
            // Desde el PR #6 un usuario sin contraseña guardada no puede entrar.
            'password_hash' => password_hash('password123', PASSWORD_BCRYPT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // [id, nombre, estado de la organización, estado de la membresía, acceso, valid_from, valid_until]
        $past = (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $future = (new \DateTimeImmutable('+2 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = [
            // Beta se inserta antes que Alfa para comprobar el orden por nombre.
            [self::ORG_BETA, 'Beta Login Org', 'active', 'active', 'collaborator', $past, $future],
            [self::ORG_ALFA, 'Alfa Login Org', 'active', 'active', 'owner', null, null],
            [self::ORG_EXPIRED, 'Caducada Login Org', 'active', 'active', 'administrator', null, $past],
            [self::ORG_SUSPENDED, 'Suspendida Login Org', 'suspended', 'active', 'owner', null, null],
            [self::ORG_PENDING, 'Futura Login Org', 'active', 'active', 'collaborator', $future, null],
        ];
        foreach ($rows as $index => [$orgId, $name, $orgStatus, $membershipStatus, $access, $from, $until]) {
            $this->connection()->insert('organizations', [
                'id' => $orgId,
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)),
                'status' => $orgStatus,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->connection()->insert('organization_memberships', [
                'id' => sprintf('c3000000-0000-4000-8000-%012d', $index + 1),
                'organization_id' => $orgId,
                'user_id' => self::USER_ID,
                'access_type' => $access,
                'status' => $membershipStatus,
                'valid_from' => $from,
                'valid_until' => $until,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function removeFixture(): void
    {
        if (null === $this->connection) {
            return;
        }

        foreach ([self::ORG_ALFA, self::ORG_BETA, self::ORG_EXPIRED, self::ORG_SUSPENDED, self::ORG_PENDING] as $orgId) {
            $this->connection->delete('organization_memberships', ['organization_id' => $orgId]);
            $this->connection->delete('organizations', ['id' => $orgId]);
        }
        $this->connection->delete('users', ['id' => self::USER_ID]);
    }

    private function client(): KernelBrowser
    {
        self::assertNotNull($this->client);

        return $this->client;
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }
}
