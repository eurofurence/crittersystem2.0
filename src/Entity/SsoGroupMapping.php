<?php

namespace App\Entity;

use App\Repository\SsoGroupMappingRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps an SSO group (reported by the provider as a structured id / "role") to
 * local entities: a department, permission groups, volunteer types and badges.
 * Applied during SSO login to provision the user's memberships.
 */
#[ORM\Entity(repositoryClass: SsoGroupMappingRepository::class)]
#[ORM\Table(name: 'sso_group_mappings')]
class SsoGroupMapping
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The provider's structured group id, e.g. "0RV39Y2PLMX1J4N6". */
    #[ORM\Column(name: 'sso_group_id', length: 64, unique: true)]
    private string $ssoGroupId;

    #[ORM\Column(length: 128)]
    private string $name = '';

    #[ORM\Column(length: 128)]
    private string $slug = '';

    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'sso_mapping_groups')]
    private Collection $permissionGroups;

    /** @var Collection<int, VolunteerType> */
    #[ORM\ManyToMany(targetEntity: VolunteerType::class)]
    #[ORM\JoinTable(name: 'sso_mapping_volunteer_types')]
    private Collection $volunteerTypes;

    /** @var Collection<int, Badge> */
    #[ORM\ManyToMany(targetEntity: Badge::class)]
    #[ORM\JoinTable(name: 'sso_mapping_badges')]
    private Collection $badges;

    public function __construct(string $ssoGroupId = '')
    {
        $this->uuid = Uuid::v4();
        $this->ssoGroupId = $ssoGroupId;
        $this->permissionGroups = new ArrayCollection();
        $this->volunteerTypes = new ArrayCollection();
        $this->badges = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSsoGroupId(): string
    {
        return $this->ssoGroupId;
    }

    public function setSsoGroupId(string $ssoGroupId): static
    {
        $this->ssoGroupId = $ssoGroupId;

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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

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

    /** @return Collection<int, Group> */
    public function getPermissionGroups(): Collection
    {
        return $this->permissionGroups;
    }

    public function addPermissionGroup(Group $group): static
    {
        if (!$this->permissionGroups->contains($group)) {
            $this->permissionGroups->add($group);
        }

        return $this;
    }

    public function clearPermissionGroups(): void
    {
        $this->permissionGroups->clear();
    }

    /** @return Collection<int, VolunteerType> */
    public function getVolunteerTypes(): Collection
    {
        return $this->volunteerTypes;
    }

    public function addVolunteerType(VolunteerType $type): static
    {
        if (!$this->volunteerTypes->contains($type)) {
            $this->volunteerTypes->add($type);
        }

        return $this;
    }

    public function clearVolunteerTypes(): void
    {
        $this->volunteerTypes->clear();
    }

    /** @return Collection<int, Badge> */
    public function getBadges(): Collection
    {
        return $this->badges;
    }

    public function addBadge(Badge $badge): static
    {
        if (!$this->badges->contains($badge)) {
            $this->badges->add($badge);
        }

        return $this;
    }

    public function clearBadges(): void
    {
        $this->badges->clear();
    }
}
