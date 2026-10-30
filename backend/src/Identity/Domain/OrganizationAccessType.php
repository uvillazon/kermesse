<?php

namespace App\Identity\Domain;

enum OrganizationAccessType: string
{
    case OWNER = 'owner';
    case ADMINISTRATOR = 'administrator';
    case COLLABORATOR = 'collaborator';

    public function canManageOrganization(): bool
    {
        return self::COLLABORATOR !== $this;
    }
}
