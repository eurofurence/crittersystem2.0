<?php

namespace App\Entity;

use App\Repository\GroupRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: 'groups')]
class Group
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    /**
     * Coarse security role this group grants (ROLE_ADMIN, ROLE_SUBADMIN,
     * ROLE_STAFF), or null for a plain user group. Drives firewall access while
     * fine-grained checks use the group's permissions.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $role = null;

    /** @var Collection<int, Privilege> */
    #[ORM\ManyToMany(targetEntity: Privilege::class, inversedBy: 'groups')]
    #[ORM\JoinTable(name: 'group_privileges')]
    private Collection $privileges;

    /** @var Collection<int, UserGroupAssignment> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: UserGroupAssignment::class)]
    private Collection $assignments;

    public function __construct(string $name, string $slug, ?string $role = null)
    {
        $this->uuid = Uuid::v4();
        $this->name = $name;
        $this->slug = $slug;
        $this->role = $role;
        $this->privileges = new ArrayCollection();
        $this->assignments = new ArrayCollection();
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

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;

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

    /** @return Collection<int, UserGroupAssignment> */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }
}
