<?php

namespace App\Entity;

use App\Repository\NeededVolunteerTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A staffing requirement: "need N volunteers of type X" attached to exactly one
 * of a shift, a location, or a shift type
 */
#[ORM\Entity(repositoryClass: NeededVolunteerTypeRepository::class)]
#[ORM\Table(name: 'needed_volunteer_types')]
class NeededVolunteerType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\Positive]
    private int $count = 1;

    #[ORM\ManyToOne(targetEntity: VolunteerType::class)]
    #[ORM\JoinColumn(name: 'volunteer_type_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private VolunteerType $volunteerType;

    #[ORM\ManyToOne(targetEntity: Shift::class, inversedBy: 'neededVolunteerTypes')]
    #[ORM\JoinColumn(name: 'shift_id', nullable: true, onDelete: 'CASCADE')]
    private ?Shift $shift = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: true, onDelete: 'CASCADE')]
    private ?Location $location = null;

    #[ORM\ManyToOne(targetEntity: ShiftType::class)]
    #[ORM\JoinColumn(name: 'shift_type_id', nullable: true, onDelete: 'CASCADE')]
    private ?ShiftType $shiftType = null;

    public function __construct(VolunteerType $volunteerType, int $count = 1)
    {
        $this->volunteerType = $volunteerType;
        $this->count = $count;
    }

    #[Assert\Callback]
    public function validateExactlyOneTarget(ExecutionContextInterface $context): void
    {
        $targets = array_filter([$this->shift, $this->location, $this->shiftType]);
        if (\count($targets) !== 1) {
            $context->buildViolation('A staffing requirement must target exactly one of a shift, location, or shift type.')
                ->atPath('count')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): static
    {
        $this->count = $count;

        return $this;
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

    public function getShift(): ?Shift
    {
        return $this->shift;
    }

    public function setShift(?Shift $shift): static
    {
        $this->shift = $shift;

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

    public function getShiftType(): ?ShiftType
    {
        return $this->shiftType;
    }

    public function setShiftType(?ShiftType $shiftType): static
    {
        $this->shiftType = $shiftType;

        return $this;
    }
}
