<?php

namespace App\Identity\Application\Access;

use App\Identity\Domain\EventAccessType;
use App\Identity\Domain\EventStatus;

final readonly class AccessScopePolicy
{
    public function __construct(private AccessDirectory $directory)
    {
    }

    public function decide(AccessRequest $request): AccessDecision
    {
        $identity = $this->directory->findIdentity($request->userId);
        if (null === $identity) {
            return AccessDecision::deny(AccessDenialReason::IDENTITY_NOT_FOUND);
        }
        if (!$identity->isActive()) {
            return AccessDecision::deny(AccessDenialReason::IDENTITY_INACTIVE);
        }

        $membership = $this->directory->findOrganizationMembership($request->userId, $request->organizationId);
        if (null === $membership) {
            return AccessDecision::deny(AccessDenialReason::ORGANIZATION_MEMBERSHIP_MISSING);
        }

        $at = $request->evaluatedAt();
        if (!$membership->isActiveAt($at)) {
            return AccessDecision::deny(AccessDenialReason::ORGANIZATION_MEMBERSHIP_INACTIVE);
        }

        if (in_array($request->intent, [AccessIntent::ORGANIZATION_READ, AccessIntent::ORGANIZATION_MANAGE], true)) {
            return $membership->accessType->canManageOrganization()
                ? AccessDecision::allow()
                : AccessDecision::deny(AccessDenialReason::ORGANIZATION_MANAGEMENT_NOT_ALLOWED);
        }

        if (null === $request->eventId) {
            return AccessDecision::deny(AccessDenialReason::EVENT_REQUIRED);
        }

        $event = $this->directory->findEvent($request->eventId);
        if (null === $event) {
            return AccessDecision::deny(AccessDenialReason::EVENT_NOT_FOUND);
        }
        if ($event->organizationId !== $request->organizationId) {
            return AccessDecision::deny(AccessDenialReason::EVENT_ORGANIZATION_MISMATCH);
        }

        $assignment = $this->directory->findEventAssignment(
            $request->userId,
            $request->organizationId,
            $request->eventId,
        );
        if (null === $assignment) {
            return AccessDecision::deny(AccessDenialReason::EVENT_ASSIGNMENT_MISSING);
        }
        if (!$assignment->isActiveAt($at)) {
            return AccessDecision::deny(AccessDenialReason::EVENT_ASSIGNMENT_INACTIVE);
        }

        if (EventStatus::CLOSED === $event->status) {
            if (EventAccessType::HISTORICAL !== $assignment->accessType) {
                return AccessDecision::deny(AccessDenialReason::HISTORICAL_ACCESS_REQUIRED);
            }
            if (AccessIntent::EVENT_WRITE === $request->intent) {
                return AccessDecision::deny(AccessDenialReason::HISTORICAL_WRITE_FORBIDDEN);
            }

            return AccessDecision::allow();
        }

        if (EventAccessType::HISTORICAL === $assignment->accessType) {
            return AccessDecision::deny(AccessDenialReason::HISTORICAL_ACCESS_CLOSED_ONLY);
        }

        return AccessDecision::allow();
    }
}
