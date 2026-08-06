<?php

namespace App\Entity;

use App\Entity\Concern\HasPublicUuid;
use App\Repository\PositionGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A department-configured grouping of Named Positions for the Advanced Matrix
 * Planner. Labels such as "Light" or "Stage" are data, never
 * hard-coded. A group belongs to exactly one department.
 */
#[ORM\Entity(repositoryClass: PositionGroupRepository::class)]
#[ORM\Table(name: 'position_groups')]
class PositionGroup
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: false, onDelete: 'CASCADE')]
    private Department $department;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    /** Presentation order within the department (columns are grouped by this). */
    #[ORM\Column(name: 'display_order', type: Types::INTEGER)]
    private int $displayOrder = 0;

    /** @var Collection<int, NamedPosition> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: NamedPosition::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $positions;

    public function __construct(Department $department, string $name)
    {
        $this->uuid = Uuid::v4();
        $this->department = $department;
        $this->name = $name;
        $this->positions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): Department
    {
        return $this->department;
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

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    /** @return Collection<int, NamedPosition> */
    public function getPositions(): Collection
    {
        return $this->positions;
    }

    public function addPosition(NamedPosition $position): static
    {
        if (!$this->positions->contains($position)) {
            $this->positions->add($position);
        }

        return $this;
    }

    public function removePosition(NamedPosition $position): static
    {
        $this->positions->removeElement($position);

        return $this;
    }
}
