<?php

namespace App\Entity;

use App\Repository\VolunteerTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VolunteerTypeRepository::class)]
#[ORM\Table(name: 'volunteer_types')]
#[UniqueEntity('name')]
class VolunteerType
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

    #[ORM\Column(name: 'contact_name', length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $contactName = null;

    #[ORM\Column(name: 'contact_dect', length: 5, nullable: true)]
    #[Assert\Length(max: 5)]
    private ?string $contactDect = null;

    #[ORM\Column(name: 'contact_email', length: 254, nullable: true)]
    #[Assert\Length(max: 254)]
    #[Assert\Email]
    private ?string $contactEmail = null;

    /** Requires supporter confirmation to join. */
    #[ORM\Column]
    private bool $restricted = false;

    /** Members can sign up for shifts without approval. */
    #[ORM\Column(name: 'shift_self_signup')]
    private bool $shiftSelfSignup = false;

    /** Visible on the public dashboard. */
    #[ORM\Column(name: 'show_on_dashboard')]
    private bool $showOnDashboard = false;

    /** Hidden from the registration page. */
    #[ORM\Column(name: 'hide_register')]
    private bool $hideRegister = false;

    /** Hidden in shift details. */
    #[ORM\Column(name: 'hide_on_shift_view')]
    private bool $hideOnShiftView = false;

    /** Visible only to staff users. */
    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    /** @var Collection<int, Department> */
    #[ORM\ManyToMany(targetEntity: Department::class, mappedBy: 'volunteerTypes')]
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

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function setContactName(?string $contactName): static
    {
        $this->contactName = $contactName;

        return $this;
    }

    public function getContactDect(): ?string
    {
        return $this->contactDect;
    }

    public function setContactDect(?string $contactDect): static
    {
        $this->contactDect = $contactDect;

        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    public function setRestricted(bool $restricted): static
    {
        $this->restricted = $restricted;

        return $this;
    }

    public function isShiftSelfSignup(): bool
    {
        return $this->shiftSelfSignup;
    }

    public function setShiftSelfSignup(bool $shiftSelfSignup): static
    {
        $this->shiftSelfSignup = $shiftSelfSignup;

        return $this;
    }

    public function isShowOnDashboard(): bool
    {
        return $this->showOnDashboard;
    }

    public function setShowOnDashboard(bool $showOnDashboard): static
    {
        $this->showOnDashboard = $showOnDashboard;

        return $this;
    }

    public function isHideRegister(): bool
    {
        return $this->hideRegister;
    }

    public function setHideRegister(bool $hideRegister): static
    {
        $this->hideRegister = $hideRegister;

        return $this;
    }

    public function isHideOnShiftView(): bool
    {
        return $this->hideOnShiftView;
    }

    public function setHideOnShiftView(bool $hideOnShiftView): static
    {
        $this->hideOnShiftView = $hideOnShiftView;

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
