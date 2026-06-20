<?php

namespace App\Entity;

use App\Repository\LocationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Table(name: 'locations')]
#[UniqueEntity('name')]
class Location
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'map_url', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $mapUrl = null;

    #[ORM\Column(length: 5, nullable: true)]
    #[Assert\Length(max: 5)]
    private ?string $dect = null;

    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    /** @var Collection<int, Department> */
    #[ORM\ManyToMany(targetEntity: Department::class, mappedBy: 'locations')]
    private Collection $departments;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->departments = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getDect(): ?string
    {
        return $this->dect;
    }

    public function setDect(?string $dect): static
    {
        $this->dect = $dect;

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
