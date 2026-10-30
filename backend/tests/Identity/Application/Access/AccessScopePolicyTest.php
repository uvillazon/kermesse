<?php

namespace App\Tests\Identity\Application\Access;

use App\Identity\Application\Access\AccessDenialReason;
use App\Identity\Application\Access\AccessIntent;
use App\Identity\Application\Access\AccessRequest;
use App\Identity\Application\Access\AccessScopePolicy;
use App\Identity\Domain\EventAccessContext;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventAssignment;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Domain\OrganizationMembership;
use App\Identity\Domain\UserIdentity;
use App\Identity\Infrastructure\InMemory\InMemoryAccessDirectory;
use PHPUnit\Framework\TestCase;

final class AccessScopePolicyTest extends TestCase
{
    private InMemoryAccessDirectory $directory;
    private AccessScopePolicy $policy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->directory = new InMemoryAccessDirectory();
        $this->policy = new AccessScopePolicy($this->directory);
        $this->now = new \DateTimeImmutable('2026-10-29T12:00:00+00:00');

        $this->directory->addIdentity(new UserIdentity('user-1', IdentityStatus::ACTIVE));
        $this->directory->addOrganizationMembership(new OrganizationMembership(
            'user-1',
            'org-a',
            OrganizationAccessType::ADMINISTRATOR,
            MembershipStatus::ACTIVE,
        ));
        $this->directory->addOrganizationMembership(new OrganizationMembership(
            'user-1',
            'org-b',
            OrganizationAccessType::COLLABORATOR,
            MembershipStatus::ACTIVE,
        ));

        $this->directory->addEvent(new EventAccessContext('event-a-open', 'org-a', EventStatus::OPEN));
        $this->directory->addEvent(new EventAccessContext('event-a-unassigned', 'org-a', EventStatus::OPEN));
        $this->directory->addEvent(new EventAccessContext('event-a-closed', 'org-a', EventStatus::CLOSED));
        $this->directory->addEvent(new EventAccessContext('event-b-open', 'org-b', EventStatus::OPEN));

        $this->directory->addEventAssignment(new EventAssignment(
            'user-1', 'org-a', 'event-a-open', EventAccessType::OPERATIONAL, MembershipStatus::ACTIVE,
        ));
        $this->directory->addEventAssignment(new EventAssignment(
            'user-1', 'org-a', 'event-a-closed', EventAccessType::HISTORICAL, MembershipStatus::ACTIVE,
        ));
        $this->directory->addEventAssignment(new EventAssignment(
            'user-1', 'org-b', 'event-b-open', EventAccessType::OPERATIONAL, MembershipStatus::ACTIVE,
        ));
    }

    public function testAdministratorCanManageOrganizationWithoutReceivingImplicitEventAccess(): void
    {
        $decision = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::ORGANIZATION_MANAGE, at: $this->now,
        ));

        self::assertTrue($decision->allowed);

        $unassigned = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::EVENT_READ, 'event-a-unassigned', $this->now,
        ));

        self::assertFalse($unassigned->allowed);
        self::assertSame(AccessDenialReason::EVENT_ASSIGNMENT_MISSING, $unassigned->reason);
    }

    public function testOperationalAssignmentAllowsWritingOnlyInsideAssignedEvent(): void
    {
        $decision = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::EVENT_WRITE, 'event-a-open', $this->now,
        ));

        self::assertTrue($decision->allowed);
    }

    public function testSameIdentityCanOperateAnEventInAnotherOrganization(): void
    {
        $decision = $this->policy->decide(new AccessRequest(
            'user-1', 'org-b', AccessIntent::EVENT_WRITE, 'event-b-open', $this->now,
        ));

        self::assertTrue($decision->allowed);
    }

    public function testHistoricalAssignmentAllowsReadAndExportButNeverWrite(): void
    {
        $read = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::EVENT_READ, 'event-a-closed', $this->now,
        ));
        $export = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::EVENT_EXPORT, 'event-a-closed', $this->now,
        ));
        $write = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::EVENT_WRITE, 'event-a-closed', $this->now,
        ));

        self::assertTrue($read->allowed);
        self::assertTrue($export->allowed);
        self::assertFalse($write->allowed);
        self::assertSame(AccessDenialReason::HISTORICAL_WRITE_FORBIDDEN, $write->reason);
    }

    public function testInactiveOrganizationMembershipBlocksEveryEventAssignment(): void
    {
        $this->directory->addOrganizationMembership(new OrganizationMembership(
            'user-1',
            'org-a',
            OrganizationAccessType::ADMINISTRATOR,
            MembershipStatus::REVOKED,
        ));

        $decision = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::EVENT_READ, 'event-a-open', $this->now,
        ));

        self::assertFalse($decision->allowed);
        self::assertSame(AccessDenialReason::ORGANIZATION_MEMBERSHIP_INACTIVE, $decision->reason);
    }

    public function testOperationalAssignmentDoesNotBecomeHistoricalAccessAutomatically(): void
    {
        $this->directory->addEventAssignment(new EventAssignment(
            'user-1', 'org-a', 'event-a-closed', EventAccessType::OPERATIONAL, MembershipStatus::ACTIVE,
        ));

        $decision = $this->policy->decide(new AccessRequest(
            'user-1', 'org-a', AccessIntent::EVENT_READ, 'event-a-closed', $this->now,
        ));

        self::assertFalse($decision->allowed);
        self::assertSame(AccessDenialReason::HISTORICAL_ACCESS_REQUIRED, $decision->reason);
    }

    public function testCollaboratorCannotManageOrganization(): void
    {
        $decision = $this->policy->decide(new AccessRequest(
            'user-1', 'org-b', AccessIntent::ORGANIZATION_MANAGE, at: $this->now,
        ));

        self::assertFalse($decision->allowed);
        self::assertSame(AccessDenialReason::ORGANIZATION_MANAGEMENT_NOT_ALLOWED, $decision->reason);
    }
}
