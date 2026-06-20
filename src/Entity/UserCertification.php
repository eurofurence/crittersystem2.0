<?php

namespace App\Entity;

use App\Repository\UserCertificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A user's certification status. Created when a user applies
 * (status=pending) and resolved by an admin/QR scan (approved) or by the
 * user themselves (self_confirmed) on certs that allow it.
 */
#[ORM\Entity(repositoryClass: UserCertificationRepository::class)]
#[ORM\Table(name: 'user_certifications')]
#[ORM\UniqueConstraint(name: 'uniq_user_certification', columns: ['user_id', 'certification_id'])]
#[ORM\HasLifecycleCallbacks]
class UserCertification
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SELF_CONFIRMED = 'self_confirmed';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_SELF_CONFIRMED,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Certification::class)]
    #[ORM\JoinColumn(name: 'certification_id', nullable: false, onDelete: 'CASCADE')]
    private Certification $certification;

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'date_certified', nullable: true)]
    private ?\DateTimeImmutable $dateCertified = null;

    #[ORM\Column(name: 'date_expires', nullable: true)]
    private ?\DateTimeImmutable $dateExpires = null;

    /** The admin who approved (null for self-confirmation or QR check-in). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'certified_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $certifiedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Certification $certification)
    {
        $this->user = $user;
        $this->certification = $certification;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCertification(): Certification
    {
        return $this->certification;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDateCertified(): ?\DateTimeImmutable
    {
        return $this->dateCertified;
    }

    public function setDateCertified(?\DateTimeImmutable $dateCertified): static
    {
        $this->dateCertified = $dateCertified;

        return $this;
    }

    public function getDateExpires(): ?\DateTimeImmutable
    {
        return $this->dateExpires;
    }

    public function setDateExpires(?\DateTimeImmutable $dateExpires): static
    {
        $this->dateExpires = $dateExpires;

        return $this;
    }

    public function getCertifiedBy(): ?User
    {
        return $this->certifiedBy;
    }

    public function setCertifiedBy(?User $certifiedBy): static
    {
        $this->certifiedBy = $certifiedBy;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->dateExpires !== null && $this->dateExpires < new \DateTimeImmutable();
    }

    public function isValid(): bool
    {
        return \in_array($this->status, [self::STATUS_APPROVED, self::STATUS_SELF_CONFIRMED], true)
            && !$this->isExpired();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
