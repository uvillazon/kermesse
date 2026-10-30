<?php

namespace App\Identity\Application\Authorization;

use App\Identity\Application\Access\AccessDecision;
use App\Identity\Application\Access\AccessDenialReason;
use App\Identity\Application\Access\AccessDirectory;
use App\Identity\Application\Access\AccessIntent;
use App\Identity\Application\Access\AccessRequest;
use App\Identity\Application\Access\AccessScopePolicy;
use App\Identity\Domain\Permission;

final readonly class AuthorizationPolicy
{
    public function __construct(
        private AccessScopePolicy $scopePolicy,
        private AccessDirectory $directory,
        private RolePermissionMap $rolePermissionMap,
    ) {
    }

    public function decide(AuthorizationRequest $request): AccessDecision
    {
        $scopeDecision = $this->scopePolicy->decide(new AccessRequest(
            $request->userId,
            $request->organizationId,
            $this->accessIntent($request->permission),
            $request->eventId,
            $request->at,
        ));
        if (!$scopeDecision->allowed) {
            return $scopeDecision;
        }

        if ($this->isOrganizationPermission($request->permission)) {
            return AccessDecision::allow();
        }

        if (null === $request->eventId) {
            return AccessDecision::deny(AccessDenialReason::EVENT_REQUIRED);
        }

        $assignment = $this->directory->findEventAssignment(
            $request->userId,
            $request->organizationId,
            $request->eventId,
        );

        if (null === $assignment || !$this->rolePermissionMap->allows($assignment, $request->permission)) {
            return AccessDecision::deny(AccessDenialReason::PERMISSION_NOT_GRANTED);
        }

        return AccessDecision::allow();
    }

    private function isOrganizationPermission(Permission $permission): bool
    {
        return in_array($permission, [
            Permission::ORGANIZATION_READ,
            Permission::ORGANIZATION_MANAGE_MEMBERS,
        ], true);
    }

    private function accessIntent(Permission $permission): AccessIntent
    {
        return match ($permission) {
            Permission::ORGANIZATION_READ => AccessIntent::ORGANIZATION_READ,
            Permission::ORGANIZATION_MANAGE_MEMBERS => AccessIntent::ORGANIZATION_MANAGE,
            Permission::REPORT_EXPORT => AccessIntent::EVENT_EXPORT,
            Permission::EVENT_MANAGE,
            Permission::CATALOG_MANAGE,
            Permission::COURSE_MANAGE,
            Permission::EXPENSE_WRITE,
            Permission::SALE_WRITE,
            Permission::PAYMENT_REVIEW,
            Permission::TICKET_REDEEM,
            Permission::EVENT_CLOSE => AccessIntent::EVENT_WRITE,
            default => AccessIntent::EVENT_READ,
        };
    }
}
