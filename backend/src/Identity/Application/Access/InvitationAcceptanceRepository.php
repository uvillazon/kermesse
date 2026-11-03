<?php

declare(strict_types=1);

namespace App\Identity\Application\Access;

use App\Identity\Domain\AccessInvitation;
use App\Identity\Domain\OrganizationAccessType;

interface InvitationAcceptanceRepository
{
    public function lockByTokenHash(string $tokenHash): ?AccessInvitation;

    /**
     * Devuelve la identidad existente o crea una nueva. Guarda $passwordHash solo si la cuenta
     * no tenía contraseña: una invitación nunca reemplaza una contraseña ya definida.
     * Falla si la cuenta queda sin contraseña.
     */
    public function resolveIdentity(
        string $newUserId,
        string $emailNormalized,
        string $displayName,
        ?string $passwordHash,
        \DateTimeImmutable $at,
    ): string;

    public function activateOrganizationMembership(
        string $newMembershipId,
        string $userId,
        string $organizationId,
        OrganizationAccessType $accessType,
        bool $replaceAccessType,
        \DateTimeImmutable $at,
    ): string;

    public function markAccepted(string $invitationId, string $userId, \DateTimeImmutable $at): void;
}
