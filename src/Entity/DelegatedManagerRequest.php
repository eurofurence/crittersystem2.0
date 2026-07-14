<?php

namespace App\Entity;

use App\Repository\DelegatedManagerRequestRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * A request to promote a department member to Delegated Shift Manager, pending a
 * Department Manager's approval. Approval creates a department-scoped,
 * time-boxed group assignment.
 */
#[ORM\Entity(repositoryClass: DelegatedManagerRequestRepository::class)]
#[ORM\Table(name: 'delegated_manager_requests')]
class DelegatedManagerRequest
{
    use HasPublicUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Department $department;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', nullable: false, onDelete: 'CASCADE')]
    private User $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'decided_at', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(Department $department, User $subject, ?User $requestedBy)
    {
        $this->uuid = Uuid::v4();
        $this->department = $department;
        $this->subject = $subject;
        $this->requestedBy = $requestedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function getSubject(): User
    {
        return $this->subject;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function decide(string $status, User $decidedBy): void
    {
        $this->status = $status;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = new \DateTimeImmutable();
    }
}
