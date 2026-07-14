<?php

namespace App\Entity;

use App\Repository\BannedIdentityRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * A hashed record of an erased/banned identity, used to prevent the same person
 * from re-registering until the next event. The SSO user id and the email are
 * hashed separately (manual accounts store only the email hash); the raw values
 * are never stored. The ban list is cleared at each new event or after 90 days.
 */
#[ORM\Entity(repositoryClass: BannedIdentityRepository::class)]
#[ORM\Table(name: 'banned_identities')]
#[ORM\Index(name: 'idx_banned_hash', columns: ['hash'])]
#[ORM\HasLifecycleCallbacks]
class BannedIdentity
{
    use HasPublicUuid;

    public const TYPE_EMAIL = 'email';
    public const TYPE_SSO = 'sso';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'hash_type', length: 16)]
    private string $hashType;

    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\Column(name: 'banned_at')]
    private \DateTimeImmutable $bannedAt;

    #[ORM\Column(name: 'appeal_requested_at', nullable: true)]
    private ?\DateTimeImmutable $appealRequestedAt = null;

    /** Human-readable ban reason. Null for legacy GDPR-erasure bans. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /** True when the ban was created automatically (e.g. the no-show threshold). */
    #[ORM\Column(name: 'is_automatic', options: ['default' => false])]
    private bool $isAutomatic = false;

    /** Live user for behavioural bans; null for hashed GDPR bans. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** No-show count at the time of an automatic ban. */
    #[ORM\Column(name: 'no_show_count', nullable: true)]
    private ?int $noShowCount = null;

    public function __construct(string $hashType, string $hash)
    {
        $this->uuid = Uuid::v4();
        $this->hashType = $hashType;
        $this->hash = $hash;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function isAutomatic(): bool
    {
        return $this->isAutomatic;
    }

    public function setAutomatic(bool $isAutomatic): static
    {
        $this->isAutomatic = $isAutomatic;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getNoShowCount(): ?int
    {
        return $this->noShowCount;
    }

    public function setNoShowCount(?int $noShowCount): static
    {
        $this->noShowCount = $noShowCount;

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->bannedAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHashType(): string
    {
        return $this->hashType;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getBannedAt(): \DateTimeImmutable
    {
        return $this->bannedAt;
    }

    public function getAppealRequestedAt(): ?\DateTimeImmutable
    {
        return $this->appealRequestedAt;
    }

    public function requestAppeal(): void
    {
        $this->appealRequestedAt ??= new \DateTimeImmutable();
    }

    public function hasAppeal(): bool
    {
        return $this->appealRequestedAt !== null;
    }
}
