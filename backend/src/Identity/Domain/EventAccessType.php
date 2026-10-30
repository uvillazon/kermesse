<?php

namespace App\Identity\Domain;

enum EventAccessType: string
{
    case OPERATIONAL = 'operational';
    case HISTORICAL = 'historical';
}
