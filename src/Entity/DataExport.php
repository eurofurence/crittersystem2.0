<?php

namespace App\Entity;

use App\Repository\DataExportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's "right to data portability" export. Generated in the background; the
 * resulting archive is downloadable via a UUID link valid for 24 hours, both
 * from the profile and from an email sent to the user.
 */
#[ORM\Entity(repositoryClass: DataExportRepository::class)]
#[ORM\Table(name: 'data_exports')]
#[ORM\HasLifecycleCallbacks]
class DataExport
{
    public const TTL_HOURS = 24;

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    /* The archive's key in the export storage (see App\Storage\ExportStorage), not a local path. */
    #[ORM\Column(name: 'file_path', length: 1024, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'downloaded_at', nullable: true)]
    private ?\DateTimeImmutable $downloadedAt = null;

    public function __construct(User $user, string $uuid)
    {
        $this->user = $user;
        $this->uuid = $uuid;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->expiresAt ??= $this->createdAt->modify('+'.self::TTL_HOURS.' hours');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function markReady(string $storageKey): void
    {
        $this->status = self::STATUS_READY;
        $this->storageKey = $storageKey;
    }

    public function markFailed(): void
    {
        $this->status = self::STATUS_FAILED;
    }

    /** The archive has been purged; the record survives so the request stays auditable. */
    public function forgetArchive(): void
    {
        $this->storageKey = null;
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

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Whether the record permits a download. The archive itself lives in the export storage, which
     * the entity cannot reach — the caller confirms the key is still there.
     */
    public function isDownloadable(?\DateTimeImmutable $now = null): bool
    {
        return $this->isReady() && !$this->isExpired($now) && $this->storageKey !== null;
    }
}
