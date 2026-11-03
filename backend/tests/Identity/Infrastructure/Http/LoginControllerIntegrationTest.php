<?php

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Http;

use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginControllerIntegrationTest extends WebTestCase
{
    private const ACTIVE_ID = 'b1000000-0000-4000-8000-000000000001';
    private const NO_PASSWORD_ID = 'b1000000-0000-4000-8000-000000000002';
    private const SUSPENDED_ID = 'b1000000-0000-4000-8000-000000000003';
    private const ACTIVE_EMAIL = 'login-active@example.test';
    private const NO_PASSWORD_EMAIL = 'login-no-password@example.test';
    private const SUSPENDED_EMAIL = 'login-suspended@example.test';
    private const PASSWORD = 'correct horse battery';

    private ?KernelBrowser $client = null;
    private ?Connection $connection = null;
    /** @var list<string> */
    private array $bucketsBefore = [];
    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $url = getenv('KERMESFERA_INTEGRATION_DATABASE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Define KERMESFERA_INTEGRATION_DATABASE_URL para ejecutar integración HTTP contra MariaDB.');
        }

        $this->setEnv('DATABASE_URL', $url);
        $this->bootClient();
        $this->bucketsBefore = $this->connection()->fetchFirstColumn('SELECT bucket_key FROM api_rate_limit_buckets');
        $this->removeFixture();
        $this->insertFixture();
    }

    protected function tearDown(): void
    {
        $this->removeFixture();
        if (null !== $this->connection) {
            $this->connection->executeStatement(
                'DELETE FROM api_rate_limit_buckets WHERE bucket_key NOT IN (?)',
                [[] === $this->bucketsBefore ? [''] : $this->bucketsBefore],
                [ArrayParameterType::STRING],
            );
        }
        $this->connection?->close();
        $this->client = null;
        foreach ($this->envBackup as $name => $value) {
            if (false === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        $this->envBackup = [];
        parent::tearDown();
    }

    public function testIssuesTokenForValidCredentials(): void
    {
        $this->login(' Login-Active@Example.Test ', self::PASSWORD);

        self::assertResponseStatusCodeSame(200);
        $response = $this->response();
        self::assertSame(self::ACTIVE_ID, $response['user']['id']);
        self::assertSame(self::ACTIVE_EMAIL, $response['user']['email']);
        self::assertSame(self::ACTIVE_ID, $this->tokenCodec()->subject(
            $response['token'],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
    }

    public function testPrototypePasswordsNoLongerGrantAccess(): void
    {
        foreach (['password123', 'any-long-password'] as $password) {
            $this->login(self::ACTIVE_EMAIL, $password);
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testRespondsWithSameGenericErrorWithoutRevealingWhetherEmailExists(): void
    {
        $bodies = [];
        foreach ([
            [self::ACTIVE_EMAIL, 'wrong-password'],
            ['login-unknown@example.test', self::PASSWORD],
            [self::NO_PASSWORD_EMAIL, self::PASSWORD],
            [self::SUSPENDED_EMAIL, self::PASSWORD],
            [self::ACTIVE_EMAIL, str_repeat('x', 100)],
        ] as [$email, $password]) {
            $this->login($email, $password);
            self::assertResponseStatusCodeSame(401);
            self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
            $bodies[] = $this->response();
        }

        self::assertSame('Credenciales inválidas.', $bodies[0]['error']['message']);
        self::assertCount(1, array_unique(array_map(
            static fn (array $body): string => json_encode($body, JSON_THROW_ON_ERROR),
            $bodies,
        )));
    }

    public function testDoesNotTrimPasswordWhitespace(): void
    {
        $this->login(self::ACTIVE_EMAIL, ' '.self::PASSWORD.' ');

        self::assertResponseStatusCodeSame(401);
    }

    public function testUpgradesOutdatedHashOnSuccessfulLogin(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('PHP sin argon2id: bcrypt es el algoritmo vigente y no hay rehash.');
        }
        $bcrypt = password_hash(self::PASSWORD, PASSWORD_BCRYPT);
        $this->connection()->update('users', ['password_hash' => $bcrypt], ['id' => self::ACTIVE_ID]);

        $this->login(self::ACTIVE_EMAIL, self::PASSWORD);

        self::assertResponseStatusCodeSame(200);
        $stored = (string) $this->connection()->fetchOne('SELECT password_hash FROM users WHERE id = ?', [self::ACTIVE_ID]);
        self::assertNotSame($bcrypt, $stored);
        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertTrue(password_verify(self::PASSWORD, $stored));
    }

    public function testLimitsAttemptsPerAccountEvenFromDifferentAddresses(): void
    {
        $this->setEnv('RATE_LIMIT_ENABLED', '1');
        $this->setEnv('RATE_LIMIT_LOGIN_PER_MINUTE', '3');
        $this->bootClient();
        $this->waitIfMinuteIsAboutToChange();

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->login(self::ACTIVE_EMAIL, 'wrong-password', '198.51.100.'.$attempt);
            self::assertResponseStatusCodeSame(401);
        }

        // La credencial correcta también se bloquea: el límite cuenta intentos, no solo fallos.
        $this->login(self::ACTIVE_EMAIL, self::PASSWORD, '198.51.100.4');
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client()->getResponse()->headers->has('Retry-After'));

        // Otra cuenta desde una IP nueva no se ve afectada.
        $this->login(self::NO_PASSWORD_EMAIL, 'whatever-password', '198.51.100.5');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLimitsAttemptsPerAddress(): void
    {
        $this->setEnv('RATE_LIMIT_ENABLED', '1');
        $this->setEnv('RATE_LIMIT_LOGIN_PER_MINUTE', '3');
        $this->bootClient();
        $this->waitIfMinuteIsAboutToChange();

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->login('login-spray-'.$attempt.'@example.test', 'wrong-password', '203.0.113.7');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('login-spray-4@example.test', 'wrong-password', '203.0.113.7');
        self::assertResponseStatusCodeSame(429);
    }

    private function login(string $email, string $password, string $remoteAddress = '127.0.0.1'): void
    {
        $this->client()->request(
            'POST',
            '/api/v1/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $remoteAddress],
            content: json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );
    }

    private function bootClient(): void
    {
        $this->connection?->close();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = $_ENV[$name] ?? getenv($name);
        }
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** Las cuotas usan ventanas de un minuto; evita que la ventana cambie a mitad de la prueba. */
    private function waitIfMinuteIsAboutToChange(): void
    {
        $second = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('s');
        if ($second >= 55) {
            sleep(61 - $second);
        }
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        $content = $this->client()->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function insertFixture(): void
    {
        $now = '2026-11-03 12:00:00';
        $hash = password_hash(self::PASSWORD, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        foreach ([
            [self::ACTIVE_ID, self::ACTIVE_EMAIL, 'active', $hash],
            [self::NO_PASSWORD_ID, self::NO_PASSWORD_EMAIL, 'active', null],
            [self::SUSPENDED_ID, self::SUSPENDED_EMAIL, 'suspended', $hash],
        ] as [$id, $email, $status, $passwordHash]) {
            $this->connection()->insert('users', [
                'id' => $id,
                'email' => $email,
                'email_normalized' => $email,
                'display_name' => 'Login User',
                'status' => $status,
                'password_hash' => $passwordHash,
                'password_updated_at' => null === $passwordHash ? null : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function removeFixture(): void
    {
        foreach ([self::ACTIVE_ID, self::NO_PASSWORD_ID, self::SUSPENDED_ID] as $id) {
            $this->connection?->delete('users', ['id' => $id]);
        }
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

    private function tokenCodec(): HmacBearerTokenCodec
    {
        return new HmacBearerTokenCodec(
            'test-auth-secret-with-at-least-32-characters',
            'kermesfera-test',
            'kermesfera-api-test',
            0,
            3600,
        );
    }
}
