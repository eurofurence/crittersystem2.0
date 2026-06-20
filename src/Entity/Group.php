<?php

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: 'groups')]
class Group
{
    /**
     * Core groups use fixed, well-known IDs (10, 20, ... 90), so IDs are assigned
     * explicitly rather than generated. See the app:install seeder.
     */
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    /** @var Collection<int, Privilege> */
    #[ORM\ManyToMany(targetEntity: Privilege::class, inversedBy: 'groups')]
    #[ORM\JoinTable(name: 'group_privileges')]
    private Collection $privileges;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'groups')]
    private Collection $users;

    public function __construct(int $id, string $name, string $slug)
    {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->privileges = new ArrayCollection();
        $this->users = new ArrayCollection();
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    /** @return Collection<int, Privilege> */
    public function getPrivileges(): Collection
    {
        return $this->privileges;
    }

    public function addPrivilege(Privilege $privilege): static
    {
        if (!$this->privileges->contains($privilege)) {
            $this->privileges->add($privilege);
        }

        return $this;
    }

    public function removePrivilege(Privilege $privilege): static
    {
        $this->privileges->removeElement($privilege);

        return $this;
    }

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }
}
