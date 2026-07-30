<?php

namespace App\Entity;

use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Repository\ShiftRepository;
use App\Entity\Concern\HasPublicUuid;
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
    use HasPublicUuid;

    public const DESCRIPTION_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title;

    /** Reaches anonymous callers through the public API and volunteers' calendars via iCal. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: self::DESCRIPTION_MAX_LENGTH)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $url = null;

    #[ORM\Column(name: 'starts_at')]
    #[Assert\NotNull]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at')]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'validation.shift.end_after_start')]
    private \DateTimeImmutable $endsAt;

    #[ORM\ManyToOne(targetEntity: ShiftTask::class)]
    #[ORM\JoinColumn(name: 'shift_task_id', nullable: true, onDelete: 'SET NULL')]
    private ?ShiftTask $shiftTask = null;

    /** Every shift belongs to exactly one department. */
    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: false, onDelete: 'CASCADE')]
    private ?Department $department = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    /** Who this shift is offered to. */
    #[ORM\Column(type: Types::STRING, length: 32, enumType: ShiftAudience::class)]
    private ShiftAudience $audience = ShiftAudience::PUBLIC_VOLUNTEER;

    /**
     * Draft shifts are invisible until published. A shift created
     * directly (admin CRUD, imports) is live; the planner explicitly opens new
     * shifts as drafts and publishes them through the lifecycle.
     */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: ShiftState::class)]
    private ShiftState $state = ShiftState::PUBLISHED;

    /**
     * When true, a volunteer cannot apply until checked in - even during setup
     * and teardown. Overrides the phase-based default.
     */
    #[ORM\Column(name: 'require_checkin')]
    private bool $requireCheckin = false;

    /**
     * Optimistic-lock version. Guards against a stale publication overwriting
     * newer planning changes.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

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

    /** Named Positions enabled on this shift (Advanced Matrix Planner). */
    #[ORM\OneToMany(mappedBy: 'shift', targetEntity: ShiftPosition::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $shiftPositions;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        // Sensible defaults so the "new shift" form has initialised times.
        $next = new \DateTimeImmutable('+1 hour');
        $this->startsAt = $next->setTime((int) $next->format('H'), 0);
        $this->endsAt = $this->startsAt->modify('+1 hour');
        $this->entries = new ArrayCollection();
        $this->neededVolunteerTypes = new ArrayCollection();
        $this->shiftPositions = new ArrayCollection();
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

    /** Blank input is stored as null so every consumer can test presence with a single null check. */
    public function setDescription(?string $description): static
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description !== '' ? $description : null;

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

    public function getShiftTask(): ?ShiftTask
    {
        return $this->shiftTask;
    }

    public function setShiftTask(?ShiftTask $shiftTask): static
    {
        $this->shiftTask = $shiftTask;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(Department $department): static
    {
        $this->department = $department;

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

    public function addEntry(ShiftEntry $entry): static
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
        }

        return $this;
    }

    public function removeEntry(ShiftEntry $entry): static
    {
        $this->entries->removeElement($entry);

        return $this;
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

    public function getAudience(): ShiftAudience
    {
        return $this->audience;
    }

    public function setAudience(ShiftAudience $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    public function getState(): ShiftState
    {
        return $this->state;
    }

    public function setState(ShiftState $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->state->isPublished();
    }

    public function isDraft(): bool
    {
        return $this->state === ShiftState::DRAFT;
    }

    public function isRequireCheckin(): bool
    {
        return $this->requireCheckin;
    }

    public function setRequireCheckin(bool $requireCheckin): static
    {
        $this->requireCheckin = $requireCheckin;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return Collection<int, ShiftPosition> */
    public function getShiftPositions(): Collection
    {
        return $this->shiftPositions;
    }

    public function addShiftPosition(ShiftPosition $shiftPosition): static
    {
        if (!$this->shiftPositions->contains($shiftPosition)) {
            $this->shiftPositions->add($shiftPosition);
        }

        return $this;
    }

    public function removeShiftPosition(ShiftPosition $shiftPosition): static
    {
        $this->shiftPositions->removeElement($shiftPosition);

        return $this;
    }
}
