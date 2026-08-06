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
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_SELF_CONFIRMED,
        self::STATUS_REJECTED,
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

    /**
     * The admin whose decision this record carries - approval, rejection or revocation alike.
     * Null when nobody decided it: a self-confirmation, or a QR check-in at the event.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'certified_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $certifiedBy = null;

    /**
     * When the last decision was taken, and why.
     *
     * Kept when a rejected record returns to pending on a fresh application: the manager deciding it
     * the second time has to see that this was already turned down once, and what for. `notes` is
     * the admin's own free-text field and is not overwritten by a decision.
     */
    #[ORM\Column(name: 'decided_at', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'decision_reason', type: Types::TEXT, nullable: true)]
    private ?string $decisionReason = null;

    /**
     * When the holder was last warned that this is about to run out.
     *
     * Kept so the reminder is sent once per validity period rather than every night the job runs.
     * Cleared whenever the record is granted again, because the next expiry is a different one.
     */
    #[ORM\Column(name: 'expiry_reminded_at', nullable: true)]
    private ?\DateTimeImmutable $expiryRemindedAt = null;

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

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getDecisionReason(): ?string
    {
        return $this->decisionReason;
    }

    public function setDecisionReason(?string $decisionReason): static
    {
        $this->decisionReason = $decisionReason;

        return $this;
    }

    public function getExpiryRemindedAt(): ?\DateTimeImmutable
    {
        return $this->expiryRemindedAt;
    }

    public function setExpiryRemindedAt(?\DateTimeImmutable $expiryRemindedAt): static
    {
        $this->expiryRemindedAt = $expiryRemindedAt;

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

    /**
     * What this record counts as today: an approved or self-confirmed certification whose expiry has
     * passed reads as expired, since the holder is no longer qualified.
     *
     * A revoked record stays revoked. Revocation is a decision somebody made about this person, and
     * letting the clock relabel it as a routine expiry would hide that from whoever looks next.
     */
    public function effectiveStatus(): string
    {
        if ($this->isExpired() && \in_array($this->status, [self::STATUS_APPROVED, self::STATUS_SELF_CONFIRMED], true)) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
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
