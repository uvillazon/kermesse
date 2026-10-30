<?php

namespace App\Identity\Application\Access;

enum AccessIntent: string
{
    case ORGANIZATION_READ = 'organization.read';
    case ORGANIZATION_MANAGE = 'organization.manage';
    case EVENT_READ = 'event.read';
    case EVENT_WRITE = 'event.write';
    case EVENT_EXPORT = 'event.export';

    public function requiresEvent(): bool
    {
        return !in_array($this, [self::ORGANIZATION_READ, self::ORGANIZATION_MANAGE], true);
    }
}
