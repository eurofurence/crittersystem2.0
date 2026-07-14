<?php

namespace App\Entity;

use App\Repository\PlanningAvailabilityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's single global Planning Availability schedule. It belongs
 * to the user, not a department: one schedule, reusable by every authorized
 * department the user is a member of. Holds the declared ranges plus an optional
 * comment for managers.
 */
#[ORM\Entity(repositoryClass: PlanningAvailabilityRepository::class)]
#[ORM\Table(name: 'planning_availabilities')]
#[ORM\HasLifecycleCallbacks]
class PlanningAvailability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    /** Free-text note for managers. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AvailabilityRange> */
    #[ORM\OneToMany(mappedBy: 'availability', targetEntity: AvailabilityRange::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startsAt' => 'ASC'])]
    private Collection $ranges;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->ranges = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, AvailabilityRange> */
    public function getRanges(): Collection
    {
        return $this->ranges;
    }

    public function addRange(AvailabilityRange $range): static
    {
        if (!$this->ranges->contains($range)) {
            $this->ranges->add($range);
        }

        return $this;
    }

    public function removeRange(AvailabilityRange $range): static
    {
        $this->ranges->removeElement($range);

        return $this;
    }

    public function clearRanges(): void
    {
        $this->ranges->clear();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onSave(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
