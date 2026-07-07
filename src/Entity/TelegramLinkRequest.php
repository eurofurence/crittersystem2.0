<?php

namespace App\Entity;

use App\Repository\TelegramLinkRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pending Telegram account-link. The user forwards the code to the bot; when
 * the bot confirms, the request is marked linked and the user's Telegram fields
 * are populated. Valid for 15 minutes.
 */
#[ORM\Entity(repositoryClass: TelegramLinkRequestRepository::class)]
#[ORM\Table(name: 'telegram_link_requests')]
#[ORM\HasLifecycleCallbacks]
class TelegramLinkRequest
{
    public const TTL_MINUTES = 15;

    public const STATUS_PENDING = 'pending';
    public const STATUS_LINKED = 'linked';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, unique: true)]
    private string $code;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'confirmed_at', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    public function __construct(User $user, string $code)
    {
        $this->user = $user;
        $this->code = $code;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->expiresAt ??= $this->createdAt->modify('+'.self::TTL_MINUTES.' minutes');
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getCode(): string { return $this->code; }
    public function getStatus(): string { return $this->status; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isLinked(): bool { return $this->status === self::STATUS_LINKED; }
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function markLinked(): void
    {
        $this->status = self::STATUS_LINKED;
        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function markExpired(): void
    {
        $this->status = self::STATUS_EXPIRED;
    }
}
