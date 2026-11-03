<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Credential\PasswordHasher;
use App\Identity\Application\Credential\PasswordPolicy;
use App\Identity\Application\Credential\UserCredentialRepository;
use App\Identity\Domain\IdentityStatus;
use App\Shared\Application\RateLimit\RateLimiter;
use App\Shared\Infrastructure\Http\ApiRequestInput;
use App\Shared\Infrastructure\RateLimit\RateLimitPolicy;
use App\Shared\Infrastructure\Security\HmacBearerTokenCodec;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LoginController
{
    private const INVALID_CREDENTIALS = 'Credenciales inválidas.';

    public function __construct(
        private HmacBearerTokenCodec $tokens,
        private Connection $connection,
        private ApiRequestInput $input,
        private UserCredentialRepository $credentials,
        private PasswordHasher $hasher,
        private RateLimiter $limiter,
        private RateLimitPolicy $rateLimits,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $this->input->object($request, ['email', 'password']);
        $email = $this->input->requiredString($payload, 'email');
        $password = (string) $this->input->password($payload, 'password');

        $norm = strtolower(trim($email));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->limitAttemptsPerAccount($norm, $now);

        $user = strlen($norm) <= 254 ? $this->credentials->findByEmail($norm) : null;
        // Una contraseña más larga que la política nunca pudo guardarse. En todos los rechazos
        // se gasta el tiempo de una verificación para no revelar si el correo existe.
        $withinPolicy = strlen($password) <= PasswordPolicy::MAX_BYTES;

        if (null === $user || null === $user['password_hash'] || !$withinPolicy) {
            $this->hasher->verifyAgainstDummy($withinPolicy ? $password : '');
            throw new UnauthorizedHttpException('Bearer', self::INVALID_CREDENTIALS);
        }
        if (!$this->hasher->verify($password, $user['password_hash'])) {
            throw new UnauthorizedHttpException('Bearer', self::INVALID_CREDENTIALS);
        }
        // Una cuenta suspendida responde igual que una contraseña incorrecta.
        if (IdentityStatus::ACTIVE->value !== $user['status']) {
            throw new UnauthorizedHttpException('Bearer', self::INVALID_CREDENTIALS);
        }
        if ($this->hasher->needsRehash($user['password_hash'])) {
            $this->credentials->updatePasswordHash($user['id'], $this->hasher->hash($password), $now);
        }

        // Consultar membresía de organización
        $orgMembership = $this->connection->fetchAssociative(
            'SELECT access_type FROM organization_memberships WHERE user_id = ? AND status = ? LIMIT 1',
            [$user['id'], 'active']
        );
        $orgAccess = false !== $orgMembership ? (string) $orgMembership['access_type'] : 'collaborator';

        // Consultar roles en eventos
        $roleRows = $this->connection->fetchAllAssociative(
            'SELECT r.code FROM roles r 
             JOIN event_membership_roles emr ON emr.role_id = r.id
             JOIN event_memberships em ON em.id = emr.event_membership_id
             WHERE em.user_id = ? AND em.status = ?',
            [$user['id'], 'active']
        );
        $roles = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['code'], $roleRows)));

        $token = $this->tokens->issue(
            $user['id'],
            $now,
            3600, // 1 hora de vigencia
            [
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'roles' => $roles,
                'orgAccess' => $orgAccess,
            ]
        );

        return new JsonResponse([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['display_name'],
                'orgAccess' => $orgAccess,
                'roles' => $roles,
            ],
        ]);
    }

    /**
     * Complementa la cuota por IP de ApiRateLimitSubscriber: limita intentos contra una misma
     * cuenta aunque lleguen desde muchas IP. La clave usa el hash del correo, nunca el correo.
     */
    private function limitAttemptsPerAccount(string $emailNormalized, \DateTimeImmutable $now): void
    {
        if (!$this->rateLimits->isEnabled()) {
            return;
        }

        $decision = $this->limiter->consume(
            'auth.login.account',
            'email:'.hash('sha256', $emailNormalized),
            $this->rateLimits->loginPerMinute(),
            $now,
        );
        if (!$decision->allowed) {
            throw new TooManyRequestsHttpException(
                $decision->retryAfterSeconds,
                'Demasiados intentos de inicio de sesión. Intente nuevamente más tarde.',
            );
        }
    }
}
