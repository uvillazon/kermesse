<?php

namespace App\Identity\Domain;

enum EventStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function allowsWrite(): bool
    {
        return self::CLOSED !== $this;
    }
}
