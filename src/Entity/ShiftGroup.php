<?php

namespace App\Entity;

use App\Entity\Concern\HasPublicUuid;
use App\Repository\ShiftGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Shifts that can only be taken together: applying to one signs the volunteer up for all of them,
 * and cancelling one cancels all of them (a rehearsal and the performance it prepares).
 *
 * A group belongs to exactly one department and every member shift must belong to that same
 * department. `shift:manage` and `shift:assign` are department-scoped and PrivilegeVoter fails open
 * when handed no subject, so a group spanning departments would be a resource with no authoritative
 * department to scope against.
 */
#[ORM\Entity(repositoryClass: ShiftGroupRepository::class)]
#[ORM\Table(name: 'shift_groups')]
class ShiftGroup
{
    use HasPublicUuid;

    public const DESCRIPTION_MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    /** Reaches volunteers through the group modal and the bot, so it is written for them. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: self::DESCRIPTION_MAX_LENGTH)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: false, onDelete: 'CASCADE')]
    private Department $department;

    /**
     * Deleting a group ungroups its shifts (the join column is ON DELETE SET NULL) rather than
     * deleting them: removing a label must never destroy the work it labelled.
     *
     * @var Collection<int, Shift>
     */
    #[ORM\OneToMany(mappedBy: 'shiftGroup', targetEntity: Shift::class)]
    #[ORM\OrderBy(['startsAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $shifts;

    public function __construct(Department $department, string $name)
    {
        $this->uuid = Uuid::v4();
        $this->department = $department;
        $this->name = $name;
        $this->shifts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function setDepartment(Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    /** @return Collection<int, Shift> */
    public function getShifts(): Collection
    {
        return $this->shifts;
    }

    public function addShift(Shift $shift): static
    {
        if (!$this->shifts->contains($shift)) {
            $this->shifts->add($shift);
            $shift->setShiftGroup($this);
        }

        return $this;
    }

    public function removeShift(Shift $shift): static
    {
        if ($this->shifts->removeElement($shift) && $shift->getShiftGroup() === $this) {
            $shift->setShiftGroup(null);
        }

        return $this;
    }

    /**
     * A group of fewer than two shifts links nothing. It is allowed to exist while a manager is
     * still filling it in, but every enforcement path treats such a shift as ungrouped.
     */
    public function isEffective(): bool
    {
        return $this->shifts->count() > 1;
    }

    public function getTotalDurationHours(): float
    {
        $hours = 0.0;
        foreach ($this->shifts as $shift) {
            $hours += $shift->getDurationHours();
        }

        return $hours;
    }
}
