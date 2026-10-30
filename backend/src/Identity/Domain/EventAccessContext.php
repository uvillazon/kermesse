<?php

namespace App\Identity\Domain;

final readonly class EventAccessContext
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public EventStatus $status,
    ) {
    }
}
