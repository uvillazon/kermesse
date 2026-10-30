<?php

namespace App\Tests\Identity\Application\Authorization;

use App\Identity\Application\Access\AccessDenialReason;
use App\Identity\Application\Access\AccessScopePolicy;
use App\Identity\Application\Authorization\AuthorizationPolicy;
use App\Identity\Application\Authorization\AuthorizationRequest;
use App\Identity\Application\Authorization\RolePermissionMap;
use App\Identity\Domain\EventAccessContext;
use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventAssignment;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\EventStatus;
use App\Identity\Domain\IdentityStatus;
use App\Identity\Domain\MembershipStatus;
use App\Identity\Domain\OrganizationAccessType;
use App\Identity\Domain\OrganizationMembership;
use App\Identity\Domain\Permission;
use App\Identity\Domain\UserIdentity;
use App\Identity\Infrastructure\InMemory\InMemoryAccessDirectory;
use PHPUnit\Framework\TestCase;

final class AuthorizationPolicyTest extends TestCase
{
    private InMemoryAccessDirectory $directory;
    private AuthorizationPolicy $policy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->directory = new InMemoryAccessDirectory();
        $this->policy = new AuthorizationPolicy(
            new AccessScopePolicy($this->directory),
            $this->directory,
            new RolePermissionMap(),
        );
        $this->now = new \DateTimeImmutable('2026-10-29T12:00:00+00:00');

        $this->directory->addIdentity(new UserIdentity('user-1', IdentityStatus::ACTIVE));
        $this->directory->addOrganizationMembership(new OrganizationMembership(
            'user-1', 'org-a', OrganizationAccessType::ADMINISTRATOR, MembershipStatus::ACTIVE,
        ));
        $this->directory->addOrganizationMembership(new OrganizationMembership(
            'user-1', 'org-b', OrganizationAccessType::COLLABORATOR, MembershipStatus::ACTIVE,
        ));
        $this->directory->addEvent(new EventAccessContext('event-open', 'org-a', EventStatus::OPEN));
        $this->directory->addEvent(new EventAccessContext('event-closed', 'org-a', EventStatus::CLOSED));
    }

    public function testOrganizationAdministratorCanManageMembers(): void
    {
        $decision = $this->decide(Permission::ORGANIZATION_MANAGE_MEMBERS);

        self::assertTrue($decision->allowed);
    }

    public function testCollaboratorCannotReadOrganizationAdministrationData(): void
    {
        $decision = $this->policy->decide(new AuthorizationRequest(
            'user-1', 'org-b', Permission::ORGANIZATION_READ, at: $this->now,
        ));

        self::assertFalse($decision->allowed);
        self::assertSame(AccessDenialReason::ORGANIZATION_MANAGEMENT_NOT_ALLOWED, $decision->reason);
    }

    public function testSalesOperatorCanSellButCannotReviewPayments(): void
    {
        $this->assignOpenEvent([EventRole::SALES_OPERATOR]);

        self::assertTrue($this->decide(Permission::SALE_WRITE, 'event-open')->allowed);

        $payment = $this->decide(Permission::PAYMENT_REVIEW, 'event-open');
        self::assertFalse($payment->allowed);
        self::assertSame(AccessDenialReason::PERMISSION_NOT_GRANTED, $payment->reason);
    }

    public function testRolesAreCombinedOnlyInsideAssignedEvent(): void
    {
        $this->assignOpenEvent([EventRole::SALES_OPERATOR, EventRole::TICKET_OPERATOR]);

        self::assertTrue($this->decide(Permission::SALE_WRITE, 'event-open')->allowed);
        self::assertTrue($this->decide(Permission::TICKET_REDEEM, 'event-open')->allowed);
        self::assertFalse($this->decide(Permission::BALANCE_READ, 'event-open')->allowed);
    }

    public function testAdditionalPermissionRepresentsExplicitDelegation(): void
    {
        $this->assignOpenEvent(
            [EventRole::EVENT_MANAGER],
            [Permission::EXPENSE_WRITE],
        );

        self::assertTrue($this->decide(Permission::EXPENSE_WRITE, 'event-open')->allowed);
        self::assertFalse($this->decide(Permission::EVENT_CLOSE, 'event-open')->allowed);
    }

    public function testOrganizationAdministratorReceivesNoImplicitEventPermission(): void
    {
        $this->assignOpenEvent([]);

        $decision = $this->decide(Permission::EVENT_READ, 'event-open');

        self::assertFalse($decision->allowed);
        self::assertSame(AccessDenialReason::PERMISSION_NOT_GRANTED, $decision->reason);
    }

    public function testHistoricalViewerCanExportClosedEventButCannotWrite(): void
    {
        $this->directory->addEventAssignment(new EventAssignment(
            'user-1',
            'org-a',
            'event-closed',
            EventAccessType::HISTORICAL,
            MembershipStatus::ACTIVE,
            roles: [EventRole::HISTORICAL_VIEWER],
            additionalPermissions: [Permission::SALE_WRITE],
        ));

        self::assertTrue($this->decide(Permission::REPORT_EXPORT, 'event-closed')->allowed);

        $write = $this->decide(Permission::SALE_WRITE, 'event-closed');
        self::assertFalse($write->allowed);
        self::assertSame(AccessDenialReason::HISTORICAL_WRITE_FORBIDDEN, $write->reason);
    }

    public function testEventClosureIsDeniedUntilItsAuthorizationRuleIsDefined(): void
    {
        $this->assignOpenEvent(
            [EventRole::FINANCE_REVIEWER],
            [Permission::EVENT_CLOSE],
        );

        $decision = $this->decide(Permission::EVENT_CLOSE, 'event-open');

        self::assertFalse($decision->allowed);
        self::assertSame(AccessDenialReason::PERMISSION_NOT_GRANTED, $decision->reason);
    }

    /** @param list<EventRole> $roles @param list<Permission> $additionalPermissions */
    private function assignOpenEvent(array $roles, array $additionalPermissions = []): void
    {
        $this->directory->addEventAssignment(new EventAssignment(
            'user-1',
            'org-a',
            'event-open',
            EventAccessType::OPERATIONAL,
            MembershipStatus::ACTIVE,
            roles: $roles,
            additionalPermissions: $additionalPermissions,
        ));
    }

    private function decide(Permission $permission, ?string $eventId = null): \App\Identity\Application\Access\AccessDecision
    {
        return $this->policy->decide(new AuthorizationRequest(
            'user-1', 'org-a', $permission, $eventId, $this->now,
        ));
    }
}
