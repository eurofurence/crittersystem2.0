<?php

namespace App\Entity;

use App\Repository\GoodieCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GoodieCategoryRepository::class)]
#[ORM\Table(name: 'goodie_categories')]
#[UniqueEntity('name')]
class GoodieCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 128, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: 'display_order')]
    private int $displayOrder = 0;

    /** @var Collection<int, GoodieItem> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: GoodieItem::class)]
    private Collection $items;

    public function __construct(string $name)
    {
        $this->uuid = Uuid::v4();
        $this->name = $name;
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
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

    /** @return Collection<int, GoodieItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }
}
