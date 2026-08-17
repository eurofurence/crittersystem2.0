<?php

namespace App\Entity;

use App\Repository\LocationRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Table(name: 'locations')]
#[UniqueEntity('name')]
#[UniqueEntity('alias')]
class Location
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    /** Stable human-friendly key. It is the identity used by the JSON import to match and update. */
    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $alias = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'map_url', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $mapUrl = null;

    /** An approved <iframe> snippet for the embedded map, sanitized on render. */
    #[ORM\Column(name: 'embed_html', type: Types::TEXT, nullable: true)]
    private ?string $embedHtml = null;

    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $phone = null;

    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    /** Parent location; null for a root. Nesting is capped at root + two levels. */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, Location> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    /** @var Collection<int, Department> */
    #[ORM\ManyToMany(targetEntity: Department::class, mappedBy: 'locations')]
    private Collection $departments;

    public function __construct(string $name)
    {
        $this->uuid = Uuid::v4();
        $this->name = $name;
        $this->children = new ArrayCollection();
        $this->departments = new ArrayCollection();
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Location> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /** 0 for a root, 1 for a child, 2 for a grandchild. The walk gives up after 10 hops so a parent cycle cannot hang it. */
    public function depth(): int
    {
        $depth = 0;
        for ($node = $this->parent; $node !== null; $node = $node->getParent()) {
            ++$depth;
            if ($depth > 10) {
                break;
            }
        }

        return $depth;
    }

    /** "Main Hall - Check-in Counter - Desk 2". */
    public function fullName(): string
    {
        $parts = [$this->name];
        for ($node = $this->parent; $node !== null; $node = $node->getParent()) {
            array_unshift($parts, $node->getName());
            if (\count($parts) > 3) {
                break;
            }
        }

        return implode(' - ', $parts);
    }

    /** Staff-only if this node or any ancestor is staff-only. */
    public function effectiveStaffOnly(): bool
    {
        for ($node = $this; $node !== null; $node = $node->getParent()) {
            if ($node->isStaffOnly()) {
                return true;
            }
        }

        return false;
    }

    #[Assert\Callback]
    public function validateDepth(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->depth() > 2) {
            $context->buildViolation('Locations may not be nested more than two levels deep.')
                ->atPath('parent')->addViolation();
        }
        for ($node = $this->parent; $node !== null; $node = $node->getParent()) {
            if ($node === $this) {
                $context->buildViolation('A location cannot be its own ancestor.')->atPath('parent')->addViolation();
                break;
            }
        }
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function setAlias(string $alias): static
    {
        $this->alias = $alias;

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

    public function getEmbedHtml(): ?string
    {
        return $this->embedHtml;
    }

    public function setEmbedHtml(?string $embedHtml): static
    {
        $this->embedHtml = $embedHtml;

        return $this;
    }

    public function getMapUrl(): ?string
    {
        return $this->mapUrl;
    }

    public function setMapUrl(?string $mapUrl): static
    {
        $this->mapUrl = $mapUrl;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

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

    /** @return Collection<int, Department> */
    public function getDepartments(): Collection
    {
        return $this->departments;
    }
}
