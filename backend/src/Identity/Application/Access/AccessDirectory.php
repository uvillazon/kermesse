<?php

namespace App\Identity\Application\Access;

use App\Identity\Domain\EventAccessContext;
use App\Identity\Domain\EventAssignment;
use App\Identity\Domain\OrganizationMembership;
use App\Identity\Domain\UserIdentity;

interface AccessDirectory
{
    public function findIdentity(string $userId): ?UserIdentity;

    public function findOrganizationMembership(string $userId, string $organizationId): ?OrganizationMembership;

    public function findEvent(string $eventId): ?EventAccessContext;

    public function findEventAssignment(string $userId, string $organizationId, string $eventId): ?EventAssignment;
}
