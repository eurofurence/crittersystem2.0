<?php

namespace App\Entity;

use App\Repository\ShiftRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ShiftRepository::class)]
#[ORM\Table(name: 'shifts')]
#[ORM\HasLifecycleCallbacks]
class Shift
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $url = null;

    #[ORM\Column(name: 'starts_at')]
    #[Assert\NotNull]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at')]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'The end must be after the start.')]
    private \DateTimeImmutable $endsAt;

    #[ORM\ManyToOne(targetEntity: ShiftType::class)]
    #[ORM\JoinColumn(name: 'shift_type_id', nullable: true, onDelete: 'SET NULL')]
    private ?ShiftType $shiftType = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    /** Groups shifts created together by a batch operation (e.g. schedule import). */
    #[ORM\Column(name: 'transaction_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $transactionId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ShiftEntry> */
    #[ORM\OneToMany(mappedBy: 'shift', targetEntity: ShiftEntry::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $entries;

    /** @var Collection<int, NeededVolunteerType> */
    #[ORM\OneToMany(mappedBy: 'shift', targetEntity: NeededVolunteerType::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $neededVolunteerTypes;

    public function __construct()
    {
        // Sensible defaults so the "new shift" form has initialised times.
        $next = new \DateTimeImmutable('+1 hour');
        $this->startsAt = $next->setTime((int) $next->format('H'), 0);
        $this->endsAt = $this->startsAt->modify('+1 hour');
        $this->entries = new ArrayCollection();
        $this->neededVolunteerTypes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getShiftType(): ?ShiftType
    {
        return $this->shiftType;
    }

    public function setShiftType(?ShiftType $shiftType): static
    {
        $this->shiftType = $shiftType;

        return $this;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getTransactionId(): ?Uuid
    {
        return $this->transactionId;
    }

    public function setTransactionId(?Uuid $transactionId): static
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
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

    /** @return Collection<int, ShiftEntry> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    /** @return Collection<int, NeededVolunteerType> */
    public function getNeededVolunteerTypes(): Collection
    {
        return $this->neededVolunteerTypes;
    }

    public function addNeededVolunteerType(NeededVolunteerType $needed): static
    {
        if (!$this->neededVolunteerTypes->contains($needed)) {
            $this->neededVolunteerTypes->add($needed);
            $needed->setShift($this);
        }

        return $this;
    }

    public function removeNeededVolunteerType(NeededVolunteerType $needed): static
    {
        if ($this->neededVolunteerTypes->removeElement($needed) && $needed->getShift() === $this) {
            $needed->setShift(null);
        }

        return $this;
    }

    public function getDurationHours(): float
    {
        return ($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 3600;
    }

    public function isPast(): bool
    {
        return $this->endsAt < new \DateTimeImmutable();
    }
}
