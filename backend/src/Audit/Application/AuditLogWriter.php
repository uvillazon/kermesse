<?php

declare(strict_types=1);

namespace App\Audit\Application;

interface AuditLogWriter
{
    public function append(AuditEntry $entry): void;
}
