<?php

namespace App\Entity;

use App\Enum\ShiftEntryState;
use App\Repository\ShiftEntryRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A volunteer's sign-up for a shift under a particular volunteer type.
 * A user may hold at most one entry per shift (see the unique constraint).
 */
#[ORM\Entity(repositoryClass: ShiftEntryRepository::class)]
#[ORM\Table(name: 'shift_entries')]
#[ORM\UniqueConstraint(name: 'uniq_shift_user', columns: ['shift_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class ShiftEntry
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shift::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'shift_id', nullable: false, onDelete: 'CASCADE')]
    private Shift $shift;

    #[ORM\ManyToOne(targetEntity: VolunteerType::class)]
    #[ORM\JoinColumn(name: 'volunteer_type_id', nullable: false, onDelete: 'CASCADE')]
    private VolunteerType $volunteerType;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Whether this is a pending application or a confirmed assignment. */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: ShiftEntryState::class)]
    private ShiftEntryState $state = ShiftEntryState::ASSIGNMENT;

    #[ORM\Column(name: 'user_comment', type: Types::TEXT, nullable: true)]
    private ?string $userComment = null;

    /**
     * True when a manager assigned this over an Avoid/Unavailable availability or
     * beyond the recommended event hours — visibly marked and audited.
     */
    #[ORM\Column]
    private bool $overridden = false;

    #[ORM\Column(name: 'override_reason', length: 255, nullable: true)]
    private ?string $overrideReason = null;

    #[ORM\Column]
    private bool $noshow = false;

    #[ORM\Column(name: 'noshow_comment', type: Types::TEXT, nullable: true)]
    private ?string $noshowComment = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * The Named Positions this single entry occupies. One entry per
     * user/shift; multiple positions attach here without extra shift assignments.
     *
     * @var Collection<int, ShiftPositionAssignment>
     */
    #[ORM\OneToMany(mappedBy: 'shiftEntry', targetEntity: ShiftPositionAssignment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $positionAssignments;

    public function __construct(Shift $shift, VolunteerType $volunteerType, User $user)
    {
        $this->uuid = Uuid::v4();
        $this->shift = $shift;
        $this->volunteerType = $volunteerType;
        $this->user = $user;
        $this->positionAssignments = new ArrayCollection();
        $shift->addEntry($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShift(): Shift
    {
        return $this->shift;
    }

    public function getVolunteerType(): VolunteerType
    {
        return $this->volunteerType;
    }

    public function setVolunteerType(VolunteerType $volunteerType): static
    {
        $this->volunteerType = $volunteerType;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getState(): ShiftEntryState
    {
        return $this->state;
    }

    public function setState(ShiftEntryState $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function isAssignment(): bool
    {
        return $this->state === ShiftEntryState::ASSIGNMENT;
    }

    public function isApplication(): bool
    {
        return $this->state === ShiftEntryState::APPLICATION;
    }

    public function isOverridden(): bool
    {
        return $this->overridden;
    }

    public function getOverrideReason(): ?string
    {
        return $this->overrideReason;
    }

    public function markOverridden(string $reason): static
    {
        $this->overridden = true;
        $this->overrideReason = $reason;

        return $this;
    }

    public function getUserComment(): ?string
    {
        return $this->userComment;
    }

    public function setUserComment(?string $userComment): static
    {
        $this->userComment = $userComment;

        return $this;
    }

    public function isNoshow(): bool
    {
        return $this->noshow;
    }

    public function setNoshow(bool $noshow): static
    {
        $this->noshow = $noshow;

        return $this;
    }

    public function getNoshowComment(): ?string
    {
        return $this->noshowComment;
    }

    public function setNoshowComment(?string $noshowComment): static
    {
        $this->noshowComment = $noshowComment;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, ShiftPositionAssignment> */
    public function getPositionAssignments(): Collection
    {
        return $this->positionAssignments;
    }

    public function addPositionAssignment(ShiftPositionAssignment $assignment): static
    {
        if (!$this->positionAssignments->contains($assignment)) {
            $this->positionAssignments->add($assignment);
        }

        return $this;
    }

    public function removePositionAssignment(ShiftPositionAssignment $assignment): static
    {
        $this->positionAssignments->removeElement($assignment);

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
