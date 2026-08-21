<?php

namespace App\Entity;

use App\Entity\Concern\HasPublicUuid;
use App\Repository\GoodieDistributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Audit record of a goodie handed to a volunteer, snapshotting their credited
 * hours at the moment of distribution.
 *
 * The row is never deleted. A handover given in error is revoked, which keeps the record and the
 * name of whoever undid it while taking the quantity out of every count: per-person limits, the
 * eligibility tiers, the volunteer's own list and the desk statistics all ignore revoked rows.
 * A revoked row therefore makes the item claimable again. Only the desk history and the GDPR
 * export still show it, because that is where the correction has to remain visible.
 */
#[ORM\Entity(repositoryClass: GoodieDistributionRepository::class)]
#[ORM\Table(name: 'goodie_distributions')]
#[ORM\HasLifecycleCallbacks]
class GoodieDistribution
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: GoodieItem::class)]
    #[ORM\JoinColumn(name: 'item_id', nullable: true, onDelete: 'SET NULL')]
    private ?GoodieItem $item;

    /** Item name kept for audit even if the item is later deleted. */
    #[ORM\Column(name: 'item_name', length: 128)]
    private string $itemName;

    #[ORM\Column]
    private int $quantity = 1;

    #[ORM\Column(name: 'hours_at_distribution')]
    private float $hoursAtDistribution = 0.0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'distributed_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $distributedBy = null;

    #[ORM\Column(name: 'distributed_at')]
    private \DateTimeImmutable $distributedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Why this handover went ahead despite the recipient missing a certification the item requires.
     * Non-null is the record that a requirement was overridden; {@see $distributedBy} says by whom.
     */
    #[ORM\Column(name: 'certification_override_reason', type: Types::TEXT, nullable: true)]
    private ?string $certificationOverrideReason = null;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'revoked_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $revokedBy = null;

    #[ORM\Column(name: 'revoke_reason', type: Types::TEXT, nullable: true)]
    private ?string $revokeReason = null;

    /** Quantity this row was created with; non-null is the record that it was corrected afterwards. */
    #[ORM\Column(name: 'original_quantity', nullable: true)]
    private ?int $originalQuantity = null;

    #[ORM\Column(name: 'corrected_at', nullable: true)]
    private ?\DateTimeImmutable $correctedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'corrected_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $correctedBy = null;

    #[ORM\Column(name: 'correction_reason', type: Types::TEXT, nullable: true)]
    private ?string $correctionReason = null;

    public function __construct(User $user, GoodieItem $item, int $quantity = 1)
    {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->item = $item;
        $this->itemName = $item->getName();
        $this->quantity = $quantity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getItem(): ?GoodieItem
    {
        return $this->item;
    }

    public function getItemName(): string
    {
        return $this->itemName;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * Amend how many were actually handed over, keeping what the row first said.
     *
     * A correction cannot empty the handover: zero is what {@see revoke()} means, and letting two
     * different states both say "nothing was given" would leave the history unable to tell a typo
     * from a withdrawal.
     *
     * @throws \InvalidArgumentException when the new quantity is below 1
     */
    public function correctQuantity(int $quantity, User $by, ?string $reason = null): static
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('A corrected quantity must be at least 1; revoke the handover instead.');
        }

        $this->originalQuantity ??= $this->quantity;
        $this->quantity = $quantity;
        $this->correctedAt = new \DateTimeImmutable();
        $this->correctedBy = $by;
        $this->correctionReason = $reason;

        return $this;
    }

    public function getHoursAtDistribution(): float
    {
        return $this->hoursAtDistribution;
    }

    public function setHoursAtDistribution(float $hoursAtDistribution): static
    {
        $this->hoursAtDistribution = $hoursAtDistribution;

        return $this;
    }

    public function getDistributedBy(): ?User
    {
        return $this->distributedBy;
    }

    public function setDistributedBy(?User $distributedBy): static
    {
        $this->distributedBy = $distributedBy;

        return $this;
    }

    public function getDistributedAt(): \DateTimeImmutable
    {
        return $this->distributedAt;
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

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->distributedAt ??= new \DateTimeImmutable();
    }

    public function getCertificationOverrideReason(): ?string
    {
        return $this->certificationOverrideReason;
    }

    public function setCertificationOverrideReason(?string $reason): static
    {
        $this->certificationOverrideReason = $reason;

        return $this;
    }

    public function isCertificationOverridden(): bool
    {
        return $this->certificationOverrideReason !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /** Revoking twice keeps the first actor and reason: the handover was already undone. */
    public function revoke(User $by, ?string $reason = null): static
    {
        if ($this->revokedAt !== null) {
            return $this;
        }

        $this->revokedAt = new \DateTimeImmutable();
        $this->revokedBy = $by;
        $this->revokeReason = $reason;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function getRevokeReason(): ?string
    {
        return $this->revokeReason;
    }

    public function isQuantityCorrected(): bool
    {
        return $this->originalQuantity !== null;
    }

    public function getOriginalQuantity(): ?int
    {
        return $this->originalQuantity;
    }

    public function getCorrectedAt(): ?\DateTimeImmutable
    {
        return $this->correctedAt;
    }

    public function getCorrectedBy(): ?User
    {
        return $this->correctedBy;
    }

    public function getCorrectionReason(): ?string
    {
        return $this->correctionReason;
    }
}
