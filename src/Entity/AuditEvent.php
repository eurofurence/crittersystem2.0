<?php

namespace App\Entity;

use App\Audit\AuditRecord;
use App\Repository\AuditEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single, immutable forensic audit entry.
 *
 * Append-only by design: there are no setters and the application never updates
 * or deletes rows. Even when a user invokes their right to erasure, existing
 * audit entries are preserved; the erasure request itself is logged as new
 * entries. Built from an {@see AuditRecord} by the async handler.
 */
#[ORM\Entity(repositoryClass: AuditEventRepository::class)]
#[ORM\Table(name: 'audit_events')]
#[ORM\Index(name: 'idx_audit_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_actor_user', columns: ['actor_user_id'])]
#[ORM\Index(name: 'idx_audit_event_type', columns: ['event_type'])]
class AuditEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'event_id', length: 64, unique: true)]
    private string $eventId;

    #[ORM\Column(name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'event_type', length: 48)]
    private string $eventType;

    #[ORM\Column(length: 48)]
    private string $action;

    #[ORM\Column(length: 16)]
    private string $outcome;

    #[ORM\Column(name: 'actor_type', length: 16)]
    private string $actorType;

    #[ORM\Column(name: 'actor_user_id', nullable: true)]
    private ?int $actorUserId = null;

    #[ORM\Column(name: 'actor_sso_id', length: 128, nullable: true)]
    private ?string $actorSsoId = null;

    #[ORM\Column(name: 'actor_username', length: 128, nullable: true)]
    private ?string $actorUsername = null;

    #[ORM\Column(name: 'actor_role', length: 32, nullable: true)]
    private ?string $actorRole = null;

    #[ORM\Column(name: 'actor_ip', length: 64, nullable: true)]
    private ?string $actorIp = null;

    #[ORM\Column(name: 'actor_user_agent', type: 'text', nullable: true)]
    private ?string $actorUserAgent = null;

    #[ORM\Column(name: 'resource_type', length: 64, nullable: true)]
    private ?string $resourceType = null;

    #[ORM\Column(name: 'resource_id', length: 128, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(name: 'resource_owner_id', length: 128, nullable: true)]
    private ?string $resourceOwnerId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $resource = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $details = [];

    #[ORM\Column(name: 'session_id', length: 128, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(name: 'request_url', type: 'text', nullable: true)]
    private ?string $requestUrl = null;

    #[ORM\Column(name: 'mfa_verified')]
    private bool $mfaVerified = false;

    #[ORM\Column(name: 'http_status', nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(AuditRecord $record)
    {
        $this->eventId = $record->eventId;
        $this->occurredAt = $record->occurredAt;
        $this->eventType = $record->eventType;
        $this->action = $record->action;
        $this->outcome = $record->outcome;
        $this->actorType = $record->actorType;
        $this->actorUserId = $record->actorUserId;
        $this->actorSsoId = $record->actorSsoId;
        $this->actorUsername = $record->actorUsername;
        $this->actorRole = $record->actorRole;
        $this->actorIp = $record->actorIp;
        $this->actorUserAgent = $record->actorUserAgent;
        $this->resourceType = $record->resourceType;
        $this->resourceId = $record->resourceId;
        $this->resourceOwnerId = $record->resourceOwnerId;
        $this->resource = $record->resource;
        $this->details = $record->details;
        $this->sessionId = $record->sessionId;
        $this->requestUrl = $record->requestUrl;
        $this->mfaVerified = $record->mfaVerified;
        $this->httpStatus = $record->httpStatus;
        $this->errorMessage = $record->errorMessage;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getActorType(): string
    {
        return $this->actorType;
    }

    public function getActorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function getActorSsoId(): ?string
    {
        return $this->actorSsoId;
    }

    public function getActorUsername(): ?string
    {
        return $this->actorUsername;
    }

    public function getActorRole(): ?string
    {
        return $this->actorRole;
    }

    public function getActorIp(): ?string
    {
        return $this->actorIp;
    }

    public function getActorUserAgent(): ?string
    {
        return $this->actorUserAgent;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function getResourceOwnerId(): ?string
    {
        return $this->resourceOwnerId;
    }

    /** @return array<string, mixed> */
    public function getResource(): array
    {
        return $this->resource;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getRequestUrl(): ?string
    {
        return $this->requestUrl;
    }

    public function isMfaVerified(): bool
    {
        return $this->mfaVerified;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
