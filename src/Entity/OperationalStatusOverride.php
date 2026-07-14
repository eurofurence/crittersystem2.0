<?php

namespace App\Entity;

use App\Repository\OperationalStatusOverrideRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's temporary manual operational status (currently only "Free to help"),
 * valid until {@see $expiresAt}. At most one row per user; the effective status
 * is computed by {@see \App\Service\OperationalStatusService} (an active shift
 * assignment always wins over an override).
 */
#[ORM\Entity(repositoryClass: OperationalStatusOverrideRepository::class)]
#[ORM\Table(name: 'operational_status_overrides')]
class OperationalStatusOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $value;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $value, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->value = $value;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt > $now;
    }
}
