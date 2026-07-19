<?php

namespace App\Entity;

use App\Repository\DepartmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'departments')]
#[UniqueEntity('name')]
#[UniqueEntity('slug')]
class Department
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

    #[ORM\Column(length: 128, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'validation.department.slug')]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    /**
     * Organizational departments cannot own shifts; the flag can
     * only be changed while the department has no shifts.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $organizational = false;

    /** @var Collection<int, Location> */
    #[ORM\ManyToMany(targetEntity: Location::class, inversedBy: 'departments')]
    #[ORM\JoinTable(name: 'department_locations')]
    private Collection $locations;

    /** @var Collection<int, VolunteerType> */
    #[ORM\ManyToMany(targetEntity: VolunteerType::class, inversedBy: 'departments')]
    #[ORM\JoinTable(name: 'department_volunteer_types')]
    private Collection $volunteerTypes;

    public function __construct(string $name, string $slug)
    {
        $this->uuid = Uuid::v4();
        $this->name = $name;
        $this->slug = $slug;
        $this->locations = new ArrayCollection();
        $this->volunteerTypes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isOrganizational(): bool
    {
        return $this->organizational;
    }

    public function setOrganizational(bool $organizational): static
    {
        $this->organizational = $organizational;

        return $this;
    }

    public function canOwnShifts(): bool
    {
        return !$this->organizational;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

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

    /** @return Collection<int, Location> */
    public function getLocations(): Collection
    {
        return $this->locations;
    }

    public function addLocation(Location $location): static
    {
        if (!$this->locations->contains($location)) {
            $this->locations->add($location);
        }

        return $this;
    }

    public function removeLocation(Location $location): static
    {
        $this->locations->removeElement($location);

        return $this;
    }

    /** @return Collection<int, VolunteerType> */
    public function getVolunteerTypes(): Collection
    {
        return $this->volunteerTypes;
    }

    public function addVolunteerType(VolunteerType $volunteerType): static
    {
        if (!$this->volunteerTypes->contains($volunteerType)) {
            $this->volunteerTypes->add($volunteerType);
        }

        return $this;
    }

    public function removeVolunteerType(VolunteerType $volunteerType): static
    {
        $this->volunteerTypes->removeElement($volunteerType);

        return $this;
    }
}
