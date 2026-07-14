<?php

namespace App\Entity;

use App\Enum\ProposalStatus;
use App\Repository\AssignmentProposalRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A draft set of suggested assignments produced by the automatic proposal engine.
 * It is never published automatically: it holds {@see ProposalAssignment}
 * suggestions a manager reviews, edits and then applies or discards.
 */
#[ORM\Entity(repositoryClass: AssignmentProposalRepository::class)]
#[ORM\Table(name: 'assignment_proposals')]
#[ORM\HasLifecycleCallbacks]
class AssignmentProposal
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: false, onDelete: 'CASCADE')]
    private Department $department;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ProposalStatus::class)]
    private ProposalStatus $status = ProposalStatus::DRAFT;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ProposalAssignment> */
    #[ORM\OneToMany(mappedBy: 'proposal', targetEntity: ProposalAssignment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $assignments;

    public function __construct(Department $department, ?User $createdBy = null)
    {
        $this->uuid = Uuid::v4();
        $this->department = $department;
        $this->createdBy = $createdBy;
        $this->assignments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function getStatus(): ProposalStatus
    {
        return $this->status;
    }

    public function setStatus(ProposalStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, ProposalAssignment> */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    public function addAssignment(ProposalAssignment $assignment): static
    {
        if (!$this->assignments->contains($assignment)) {
            $this->assignments->add($assignment);
        }

        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
