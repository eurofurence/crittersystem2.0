<?php

namespace App\Entity;

use App\Repository\InviteTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A one-time invitation link for a manually created account. Valid for 24 hours;
 * if never used, the account is cleaned up and admins are notified.
 */
#[ORM\Entity(repositoryClass: InviteTokenRepository::class)]
#[ORM\Table(name: 'invite_tokens')]
#[ORM\HasLifecycleCallbacks]
class InviteToken
{
    public const TTL_HOURS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
