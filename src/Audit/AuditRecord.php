<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Immutable, serialisable description of a single auditable event. Built on the
 * request path by {@see AuditLogger} (capturing actor + request context), then
 * dispatched on the message bus so the actual database write happens off the
 * request path. The async handler turns it into an {@see \App\Entity\AuditEvent}.
 */
final readonly class AuditRecord
{
    /**
     * @param array<string, mixed> $resource arbitrary resource details
     * @param array<string, mixed> $details  arbitrary extra context
     */
    public function __construct(
        public string $eventId,
        public \DateTimeImmutable $occurredAt,
        public string $eventType,
        public string $action,
        public string $outcome,
        public string $actorType,
        public ?int $actorUserId,
        public ?string $actorSsoId,
        public ?string $actorUsername,
        public ?string $actorRole,
        public ?string $actorIp,
        public ?string $actorUserAgent,
        public ?string $resourceType,
        public ?string $resourceId,
        public ?string $resourceOwnerId,
        public array $resource,
        public array $details,
        public ?string $sessionId,
        public ?string $requestUrl,
        public bool $mfaVerified,
        public ?int $httpStatus,
        public ?string $errorMessage,
    ) {
    }
}
