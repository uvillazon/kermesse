<?php

namespace App\Identity\Application\Access;

enum AccessDenialReason: string
{
    case IDENTITY_NOT_FOUND = 'identity_not_found';
    case IDENTITY_INACTIVE = 'identity_inactive';
    case ORGANIZATION_MEMBERSHIP_MISSING = 'organization_membership_missing';
    case ORGANIZATION_MEMBERSHIP_INACTIVE = 'organization_membership_inactive';
    case ORGANIZATION_MANAGEMENT_NOT_ALLOWED = 'organization_management_not_allowed';
    case EVENT_REQUIRED = 'event_required';
    case EVENT_NOT_FOUND = 'event_not_found';
    case EVENT_ORGANIZATION_MISMATCH = 'event_organization_mismatch';
    case EVENT_ASSIGNMENT_MISSING = 'event_assignment_missing';
    case EVENT_ASSIGNMENT_INACTIVE = 'event_assignment_inactive';
    case HISTORICAL_ACCESS_REQUIRED = 'historical_access_required';
    case HISTORICAL_ACCESS_CLOSED_ONLY = 'historical_access_closed_only';
    case HISTORICAL_WRITE_FORBIDDEN = 'historical_write_forbidden';
    case PERMISSION_NOT_GRANTED = 'permission_not_granted';
}
