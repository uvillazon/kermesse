<?php

namespace App\Identity\Infrastructure\InMemory;

use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Domain\EventAccessContext;
use App\Identity\Domain\EventAssignment;
use App\Identity\Domain\OrganizationMembership;
use App\Identity\Domain\UserIdentity;

final class InMemoryAccessDirectory implements AccessDirectory
{
    /** @var array<string, UserIdentity> */
    private array $identities = [];

    /** @var array<string, OrganizationMembership> */
    private array $organizationMemberships = [];

    /** @var array<string, EventAccessContext> */
    private array $events = [];

    /** @var array<string, EventAssignment> */
    private array $eventAssignments = [];

    public function addIdentity(UserIdentity $identity): void
    {
        $this->identities[$identity->id] = $identity;
    }

    public function addOrganizationMembership(OrganizationMembership $membership): void
    {
        $this->organizationMemberships[$this->organizationKey($membership->userId, $membership->organizationId)] = $membership;
    }

    public function addEvent(EventAccessContext $event): void
    {
        $this->events[$event->id] = $event;
    }

    public function addEventAssignment(EventAssignment $assignment): void
    {
        $this->eventAssignments[$this->eventKey($assignment->userId, $assignment->organizationId, $assignment->eventId)] = $assignment;
    }

    public function findIdentity(string $userId): ?UserIdentity
    {
        return $this->identities[$userId] ?? null;
    }

    public function findOrganizationMembership(string $userId, string $organizationId): ?OrganizationMembership
    {
        return $this->organizationMemberships[$this->organizationKey($userId, $organizationId)] ?? null;
    }

    public function findEvent(string $eventId): ?EventAccessContext
    {
        return $this->events[$eventId] ?? null;
    }

    public function findEventAssignment(string $userId, string $organizationId, string $eventId): ?EventAssignment
    {
        return $this->eventAssignments[$this->eventKey($userId, $organizationId, $eventId)] ?? null;
    }

    private function organizationKey(string $userId, string $organizationId): string
    {
        return $userId.'@'.$organizationId;
    }

    private function eventKey(string $userId, string $organizationId, string $eventId): string
    {
        return $userId.'@'.$organizationId.'@'.$eventId;
    }
}
