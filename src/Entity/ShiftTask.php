<?php

namespace App\Entity;

use App\Repository\ShiftTaskRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Names are unique within a department, not globally: two departments may each run a "Briefing".
 * The global pool (department_id IS NULL) keeps unique names of its own, enforced by the partial
 * index `uniq_shift_task_global_name`, which Doctrine's mapping cannot express and which every
 * generated migration therefore proposes to drop.
 */
#[ORM\Entity(repositoryClass: ShiftTaskRepository::class)]
#[ORM\Table(name: 'shift_tasks')]
#[ORM\UniqueConstraint(name: 'uniq_shift_task_department_name', columns: ['department_id', 'name'])]
#[UniqueEntity(fields: ['name', 'department'], message: 'validation.shift_task.name_unique', errorPath: 'name')]
class ShiftTask
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    public function __construct(string $name)
    {
        $this->uuid = Uuid::v4();
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isGlobal(): bool
    {
        return $this->department === null;
    }

    /**
     * Rendered form when attached to a shift: "[Department]: [Task]"
     * for department tasks, or just the name for global tasks.
     */
    public function displayName(): string
    {
        return $this->department !== null
            ? $this->department->getName().': '.$this->name
            : $this->name;
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

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isStaffOnly(): bool
    {
        return $this->staffOnly;
    }

    public function setStaffOnly(bool $staffOnly): static
    {
        $this->staffOnly = $staffOnly;

        return $this;
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
}
