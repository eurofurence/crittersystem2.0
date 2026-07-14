<?php

namespace App\Entity;

use App\Enum\AvailabilityValue;
use App\Repository\AvailabilityRangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One declared time range within a user's {@see PlanningAvailability} schedule.
 * Stores the submitted preference; the effective availability (after subtracting
 * confirmed assignments) is computed, not stored.
 */
#[ORM\Entity(repositoryClass: AvailabilityRangeRepository::class)]
#[ORM\Table(name: 'availability_ranges')]
#[ORM\Index(name: 'idx_avail_range_span', columns: ['starts_at', 'ends_at'])]
class AvailabilityRange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningAvailability::class, inversedBy: 'ranges')]
    #[ORM\JoinColumn(name: 'availability_id', nullable: false, onDelete: 'CASCADE')]
    private PlanningAvailability $availability;

    #[ORM\Column(name: 'starts_at')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AvailabilityValue::class)]
    private AvailabilityValue $value;

    public function __construct(PlanningAvailability $availability, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, AvailabilityValue $value)
    {
        $this->availability = $availability;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->value = $value;
        $availability->addRange($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAvailability(): PlanningAvailability
    {
        return $this->availability;
    }

    public function getUser(): User
    {
        return $this->availability->getUser();
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getValue(): AvailabilityValue
    {
        return $this->value;
    }

    public function setValue(AvailabilityValue $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function overlaps(\DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return $this->startsAt < $end && $this->endsAt > $start;
    }
}
