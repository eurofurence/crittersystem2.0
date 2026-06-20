<?php

namespace App\Entity;

use App\Repository\GoodieDistributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit record of a goodie handed to a volunteer, snapshotting their credited
 * hours at the moment of distribution
 */
#[ORM\Entity(repositoryClass: GoodieDistributionRepository::class)]
#[ORM\Table(name: 'goodie_distributions')]
#[ORM\HasLifecycleCallbacks]
class GoodieDistribution
{
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

    public function __construct(User $user, GoodieItem $item, int $quantity = 1)
    {
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

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

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
}
