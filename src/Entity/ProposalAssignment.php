<?php

namespace App\Entity;

use App\Enum\AvailabilityValue;
use App\Repository\ProposalAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single suggested assignment within an {@see AssignmentProposal}.
 * It is not a {@see ShiftEntry} and consumes nothing until the manager applies
 * the proposal.
 */
#[ORM\Entity(repositoryClass: ProposalAssignmentRepository::class)]
#[ORM\Table(name: 'proposal_assignments')]
class ProposalAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssignmentProposal::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(name: 'proposal_id', nullable: false, onDelete: 'CASCADE')]
    private AssignmentProposal $proposal;

    #[ORM\ManyToOne(targetEntity: Shift::class)]
    #[ORM\JoinColumn(name: 'shift_id', nullable: false, onDelete: 'CASCADE')]
    private Shift $shift;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: VolunteerType::class)]
    #[ORM\JoinColumn(name: 'volunteer_type_id', nullable: false, onDelete: 'CASCADE')]
    private VolunteerType $volunteerType;

    /** The availability value the suggestion was ranked on, for the reviewer. */
    #[ORM\Column(name: 'availability_value', type: Types::STRING, length: 16, nullable: true, enumType: AvailabilityValue::class)]
    private ?AvailabilityValue $availabilityValue = null;

    public function __construct(AssignmentProposal $proposal, Shift $shift, User $user, VolunteerType $volunteerType, ?AvailabilityValue $availabilityValue = null)
    {
        $this->proposal = $proposal;
        $this->shift = $shift;
        $this->user = $user;
        $this->volunteerType = $volunteerType;
        $this->availabilityValue = $availabilityValue;
        $proposal->addAssignment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProposal(): AssignmentProposal
    {
        return $this->proposal;
    }

    public function getShift(): Shift
    {
        return $this->shift;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVolunteerType(): VolunteerType
    {
        return $this->volunteerType;
    }

    public function getAvailabilityValue(): ?AvailabilityValue
    {
        return $this->availabilityValue;
    }
}
