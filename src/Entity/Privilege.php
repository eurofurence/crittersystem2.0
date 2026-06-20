<?php

namespace App\Entity;

use App\Repository\PrivilegeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrivilegeRepository::class)]
#[ORM\Table(name: 'privileges')]
class Privilege
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, mappedBy: 'privileges')]
    private Collection $groups;

    public function __construct(string $name, ?string $description = null)
    {
        $this->name = $name;
        $this->description = $description;
        $this->groups = new ArrayCollection();
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

    /** @return Collection<int, Group> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }
}
