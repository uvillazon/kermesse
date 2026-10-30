<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure\Doctrine;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditLogWriter;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAuditLogWriter implements AuditLogWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(AuditEntry $entry): void
    {
        $this->connection->insert('audit_logs', [
            'id' => $entry->id,
            'organization_id' => $entry->organizationId,
            'event_id' => $entry->eventId,
            'actor_user_id' => $entry->actorUserId,
            'action' => $entry->action,
            'aggregate_type' => $entry->aggregateType,
            'aggregate_id' => $entry->aggregateId,
            'request_id' => $entry->requestId,
            'metadata_json' => json_encode($entry->metadata, JSON_THROW_ON_ERROR),
            'created_at' => $entry->createdAt->format('Y-m-d H:i:s'),
        ]);
    }
}
