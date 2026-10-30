<?php

namespace App\Identity\Domain;

enum IdentityStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
}
