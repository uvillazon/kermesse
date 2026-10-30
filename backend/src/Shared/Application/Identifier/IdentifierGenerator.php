<?php

declare(strict_types=1);

namespace App\Shared\Application\Identifier;

interface IdentifierGenerator
{
    public function generate(): string;
}
