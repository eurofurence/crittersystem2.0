<?php

namespace App\Entity;

use App\Repository\ShiftPositionAssignmentRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Associates one Named Position (via its per-shift {@see ShiftPosition}) with a
 * single {@see ShiftEntry}. One user holds exactly one ShiftEntry
 * per shift and may hold multiple positions through several of these rows -
 * hours count the shift time only once. Position additions/removals are audited.
 */
#[ORM\Entity(repositoryClass: ShiftPositionAssignmentRepository::class)]
#[ORM\Table(name: 'shift_position_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_entry_position', columns: ['shift_entry_id', 'shift_position_id'])]
#[ORM\HasLifecycleCallbacks]
class ShiftPositionAssignment
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShiftEntry::class, inversedBy: 'positionAssignments')]
    #[ORM\JoinColumn(name: 'shift_entry_id', nullable: false, onDelete: 'CASCADE')]
    private ShiftEntry $shiftEntry;

    #[ORM\ManyToOne(targetEntity: ShiftPosition::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(name: 'shift_position_id', nullable: false, onDelete: 'CASCADE')]
    private ShiftPosition $shiftPosition;

    /** Optional per-cell note for this specific occupant. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(ShiftEntry $shiftEntry, ShiftPosition $shiftPosition)
    {
        $this->uuid = Uuid::v4();
        $this->shiftEntry = $shiftEntry;
        $this->shiftPosition = $shiftPosition;
        $shiftEntry->addPositionAssignment($this);
        $shiftPosition->addAssignment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShiftEntry(): ShiftEntry
    {
        return $this->shiftEntry;
    }

    public function getShiftPosition(): ShiftPosition
    {
        return $this->shiftPosition;
    }

    public function getUser(): User
    {
        return $this->shiftEntry->getUser();
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
