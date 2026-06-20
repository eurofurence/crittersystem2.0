<?php

namespace App\Entity;

use App\Repository\DigitalIdTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Short-lived public lookup token for a user's digital ID QR (legacy parity).
 * The token is what gets encoded into the QR; the public profile URL is
 * /digital-id/verify/{token}. Tokens rotate every {@see ::DEFAULT_TTL_SECONDS}.
 */
#[ORM\Entity(repositoryClass: DigitalIdTokenRepository::class)]
#[ORM\Table(name: 'digital_id_tokens')]
class DigitalIdToken
{
    public const DEFAULT_TTL_SECONDS = 150;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $user, int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
        $this->user = $user;
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify("+{$ttlSeconds} seconds");
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
