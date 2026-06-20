<?php

namespace App\Entity;

use App\Repository\DutyRecordRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A staff member's on-duty session in a duty area (a Department). Open while ended_at is null.
 */
#[ORM\Entity(repositoryClass: DutyRecordRepository::class)]
#[ORM\Table(name: 'duty_records')]
#[ORM\HasLifecycleCallbacks]
class DutyRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Duty area — derived from existing Departments. */
    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** True when ended automatically (e.g. a cleanup) rather than by the user. */
    #[ORM\Column(name: 'auto_ended')]
    private bool $autoEnded = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, ?Department $department)
    {
        $this->user = $user;
        $this->department = $department;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function isAutoEnded(): bool
    {
        return $this->autoEnded;
    }

    public function setAutoEnded(bool $autoEnded): static
    {
        $this->autoEnded = $autoEnded;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->endedAt === null;
    }

    public function getDurationHours(): float
    {
        $end = $this->endedAt ?? new \DateTimeImmutable();

        return ($end->getTimestamp() - $this->startedAt->getTimestamp()) / 3600;
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
