<?php

namespace App\Entity;

use App\Repository\PasswordResetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetRepository::class)]
#[ORM\Table(name: 'password_resets')]
class PasswordReset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ?string $token = null)
    {
        $this->user = $user;
        $this->token = $token ?? bin2hex(random_bytes(16));
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

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(int $ttlSeconds): bool
    {
        return $this->createdAt->getTimestamp() + $ttlSeconds < time();
    }
}
