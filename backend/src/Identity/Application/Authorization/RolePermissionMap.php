<?php

namespace App\Identity\Application\Authorization;

use App\Identity\Domain\EventAssignment;
use App\Identity\Domain\EventRole;
use App\Identity\Domain\Permission;

final class RolePermissionMap
{
    public function allows(EventAssignment $assignment, Permission $permission): bool
    {
        if (Permission::EVENT_CLOSE === $permission) {
            return false;
        }

        if (in_array($permission, $assignment->additionalPermissions, true)) {
            return true;
        }

        foreach ($assignment->roles as $role) {
            if (in_array($permission, $this->permissionsFor($role), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Permission> */
    public function permissionsFor(EventRole $role): array
    {
        return match ($role) {
            EventRole::EVENT_MANAGER => [
                Permission::EVENT_READ,
                Permission::EVENT_MANAGE,
                Permission::CATALOG_MANAGE,
                Permission::COURSE_MANAGE,
                Permission::TICKET_READ,
                Permission::REPORT_READ,
            ],
            EventRole::FINANCE_REVIEWER => [
                Permission::EVENT_READ,
                Permission::PAYMENT_REVIEW,
                Permission::REPORT_READ,
                Permission::REPORT_EXPORT,
                Permission::BALANCE_READ,
            ],
            EventRole::SALES_OPERATOR => [
                Permission::EVENT_READ,
                Permission::SALE_WRITE,
            ],
            EventRole::PAYMENT_REVIEWER => [
                Permission::EVENT_READ,
                Permission::PAYMENT_REVIEW,
            ],
            EventRole::TICKET_OPERATOR => [
                Permission::EVENT_READ,
                Permission::TICKET_READ,
                Permission::TICKET_REDEEM,
            ],
            EventRole::REPORT_VIEWER => [
                Permission::EVENT_READ,
                Permission::REPORT_READ,
                Permission::REPORT_EXPORT,
            ],
            EventRole::HISTORICAL_VIEWER => [
                Permission::EVENT_READ,
                Permission::TICKET_READ,
                Permission::REPORT_READ,
                Permission::REPORT_EXPORT,
                Permission::BALANCE_READ,
                Permission::AUDIT_READ,
            ],
        };
    }
}
