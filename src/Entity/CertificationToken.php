<?php

namespace App\Entity;

use App\Repository\CertificationTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Short-lived token for the certification check-in QR. An admin opens
 * the QR page for a certification; scanning the QR (after login) approves the
 * scanning user's pending application for that certification.
 */
#[ORM\Entity(repositoryClass: CertificationTokenRepository::class)]
#[ORM\Table(name: 'certification_tokens')]
class CertificationToken
{
    public const DEFAULT_TTL_SECONDS = 300;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Certification::class)]
    #[ORM\JoinColumn(name: 'certification_id', nullable: false, onDelete: 'CASCADE')]
    private Certification $certification;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(Certification $certification, int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
        $this->certification = $certification;
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify("+{$ttlSeconds} seconds");
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCertification(): Certification
    {
        return $this->certification;
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
