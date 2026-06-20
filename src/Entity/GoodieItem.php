<?php

namespace App\Entity;

use App\Repository\GoodieItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GoodieItemRepository::class)]
#[ORM\Table(name: 'goodie_items')]
class GoodieItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: GoodieCategory::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private GoodieCategory $category;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Minimum credited volunteer hours required to claim this item. */
    #[ORM\Column(name: 'required_hours')]
    #[Assert\PositiveOrZero]
    private float $requiredHours = 0.0;

    /** Quantity limit per person; null means unlimited. */
    #[ORM\Column(name: 'max_per_person', nullable: true)]
    #[Assert\Positive]
    private ?int $maxPerPerson = null;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: 'display_order')]
    private int $displayOrder = 0;

    public function __construct(GoodieCategory $category, string $name)
    {
        $this->uuid = Uuid::v4();
        $this->category = $category;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getCategory(): GoodieCategory
    {
        return $this->category;
    }

    public function setCategory(GoodieCategory $category): static
    {
        $this->category = $category;

        return $this;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getRequiredHours(): float
    {
        return $this->requiredHours;
    }

    public function setRequiredHours(float $requiredHours): static
    {
        $this->requiredHours = $requiredHours;

        return $this;
    }

    public function getMaxPerPerson(): ?int
    {
        return $this->maxPerPerson;
    }

    public function setMaxPerPerson(?int $maxPerPerson): static
    {
        $this->maxPerPerson = $maxPerPerson;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

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
}
