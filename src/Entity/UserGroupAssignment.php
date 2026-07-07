<?php

namespace App\Entity;

use App\Repository\UserGroupAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's membership in a permission group, optionally scoped to a department
 * and/or time-boxed.
 *
 *  - department null  -> the group's permissions apply everywhere (global grant).
 *  - department set   -> the group's scoped permissions apply only to resources
 *                        in that department (e.g. a department manager).
 *  - expiresAt null   -> permanent; a past expiresAt makes the assignment inert
 *                        (e.g. a delegated shift manager until the event ends).
 */
#[ORM\Entity(repositoryClass: UserGroupAssignmentRepository::class)]
#[ORM\Table(name: 'user_group_assignments')]
#[ORM\UniqueConstraint(name: 'uniq_user_group_department', columns: ['user_id', 'group_id', 'department_id'])]
#[ORM\HasLifecycleCallbacks]
class UserGroupAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'groupAssignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Group::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Group $group;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Department $department = null;

    #[ORM\Column(name: 'expires_at', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Group $group, ?Department $department = null, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->user = $user;
        $this->group = $group;
        $this->department = $department;
        $this->expiresAt = $expiresAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Active when it has no expiry or the expiry is still in the future. */
    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt === null || $this->expiresAt > ($now ?? new \DateTimeImmutable());
    }
}
