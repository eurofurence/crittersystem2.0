<?php

namespace App\Entity;

use App\Repository\ShiftPositionRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Named Position enabled on a specific shift. Carries the
 * per-shift required/open state and a free-text position note, and holds the
 * assignments occupying it. A shift enables a position at most once.
 */
#[ORM\Entity(repositoryClass: ShiftPositionRepository::class)]
#[ORM\Table(name: 'shift_positions')]
#[ORM\UniqueConstraint(name: 'uniq_shift_position', columns: ['shift_id', 'named_position_id'])]
class ShiftPosition
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shift::class, inversedBy: 'shiftPositions')]
    #[ORM\JoinColumn(name: 'shift_id', nullable: false, onDelete: 'CASCADE')]
    private Shift $shift;

    #[ORM\ManyToOne(targetEntity: NamedPosition::class)]
    #[ORM\JoinColumn(name: 'named_position_id', nullable: false, onDelete: 'CASCADE')]
    private NamedPosition $namedPosition;

    /**
     * Whether the position is required for this shift. False models the stage
     * reference `-` (not required for this shift); true unfilled models `?`.
     */
    #[ORM\Column]
    private bool $required = true;

    /** Free-text planning note for this position on this shift. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** @var Collection<int, ShiftPositionAssignment> */
    #[ORM\OneToMany(mappedBy: 'shiftPosition', targetEntity: ShiftPositionAssignment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $assignments;

    public function __construct(Shift $shift, NamedPosition $namedPosition)
    {
        $this->uuid = Uuid::v4();
        $this->shift = $shift;
        $this->namedPosition = $namedPosition;
        $this->assignments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShift(): Shift
    {
        return $this->shift;
    }

    public function getNamedPosition(): NamedPosition
    {
        return $this->namedPosition;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /** @return Collection<int, ShiftPositionAssignment> */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    public function addAssignment(ShiftPositionAssignment $assignment): static
    {
        if (!$this->assignments->contains($assignment)) {
            $this->assignments->add($assignment);
        }

        return $this;
    }

    public function removeAssignment(ShiftPositionAssignment $assignment): static
    {
        $this->assignments->removeElement($assignment);

        return $this;
    }

    public function getCapacity(): int
    {
        return $this->namedPosition->getCapacity();
    }

    public function isFull(): bool
    {
        return $this->assignments->count() >= $this->getCapacity();
    }

    /**
     * Structured cell state: 'filled', 'open' (required, unfilled,
     * the `?`), or 'not_required' (the `-`).
     */
    public function cellState(): string
    {
        if ($this->assignments->count() > 0) {
            return 'filled';
        }

        return $this->required ? 'open' : 'not_required';
    }
}
