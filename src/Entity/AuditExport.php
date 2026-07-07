<?php

namespace App\Entity;

use App\Repository\AuditExportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bookkeeping for a generated legal audit export: who requested it, the scope,
 * the integrity hash, where the package lives and when it expires. The file is
 * retained for download for a fixed window; the download itself (and expiry) are
 * recorded as audit events, not on this row.
 */
#[ORM\Entity(repositoryClass: AuditExportRepository::class)]
#[ORM\Table(name: 'audit_exports')]
#[ORM\HasLifecycleCallbacks]
class AuditExport
{
    public const RETENTION_DAYS = 90;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(name: 'requested_by_user_id', nullable: true)]
    private ?int $requestedByUserId;

    #[ORM\Column(name: 'requested_by_username', length: 128)]
    private string $requestedByUsername;

    #[ORM\Column(name: 'scope_start')]
    private \DateTimeImmutable $scopeStart;

    #[ORM\Column(name: 'scope_end')]
    private \DateTimeImmutable $scopeEnd;

    #[ORM\Column(name: 'focus_user_id', nullable: true)]
    private ?int $focusUserId;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(name: 'file_path', length: 1024)]
    private string $filePath;

    #[ORM\Column(name: 'event_count')]
    private int $eventCount;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'downloaded_at', nullable: true)]
    private ?\DateTimeImmutable $downloadedAt = null;

    public function __construct(
        string $uuid,
        ?int $requestedByUserId,
        string $requestedByUsername,
        \DateTimeImmutable $scopeStart,
        \DateTimeImmutable $scopeEnd,
        ?int $focusUserId,
        string $sha256,
        string $filePath,
        int $eventCount,
    ) {
        $this->uuid = $uuid;
        $this->requestedByUserId = $requestedByUserId;
        $this->requestedByUsername = $requestedByUsername;
        $this->scopeStart = $scopeStart;
        $this->scopeEnd = $scopeEnd;
        $this->focusUserId = $focusUserId;
        $this->sha256 = $sha256;
        $this->filePath = $filePath;
        $this->eventCount = $eventCount;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->expiresAt ??= $this->createdAt->modify('+'.self::RETENTION_DAYS.' days');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getRequestedByUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function getRequestedByUsername(): string
    {
        return $this->requestedByUsername;
    }

    public function getScopeStart(): \DateTimeImmutable
    {
        return $this->scopeStart;
    }

    public function getScopeEnd(): \DateTimeImmutable
    {
        return $this->scopeEnd;
    }

    public function getFocusUserId(): ?int
    {
        return $this->focusUserId;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getEventCount(): int
    {
        return $this->eventCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getDownloadedAt(): ?\DateTimeImmutable
    {
        return $this->downloadedAt;
    }

    public function markDownloaded(\DateTimeImmutable $at): void
    {
        $this->downloadedAt = $at;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function fileExists(): bool
    {
        return is_file($this->filePath);
    }
}
