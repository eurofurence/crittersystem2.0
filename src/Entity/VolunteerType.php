<?php

namespace App\Entity;

use App\Repository\VolunteerTypeRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
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
    use HasPublicUuid;

    public const SORT_ORDER_DEFAULT = 100;

    /**
     * The two types the system hands out by itself, on top of whatever an event names them.
     *
     * Onboarding gives every finishing user one of these, and the installer re-seeds them. Both used
     * to find them by the English name they ship with, which an administrator is free to change -
     * rename Volunteer to Critter and onboarding silently stopped assigning anything, while still
     * telling the user it had finished. The role is what those lookups match on now; the name is a
     * label and nothing depends on it.
     */
    public const ROLE_VOLUNTEER = 'volunteer';
    public const ROLE_STAFF = 'staff';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name;

    /** Null for every type an administrator creates; only the seeded base types carry one. */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'contact_name', length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $contactName = null;

    #[ORM\Column(name: 'contact_phone', length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $contactPhone = null;

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

    /** Visible only to staff in the owning department. Requires staffOnly. */
    #[ORM\Column(name: 'department_only', options: ['default' => false])]
    private bool $departmentOnly = false;

    /**
     * Part of the shared vocabulary every department draws on. A global type cannot be claimed by a
     * department (it is not offered on the department form) and cannot be restricted to one, so no
     * department's edit can take it away from the others. The base types are global: losing Staff or
     * Volunteer from a department's pickers leaves it unable to staff anything.
     */
    #[ORM\Column(name: 'is_global', options: ['default' => false])]
    private bool $global = false;

    /** Ranks the type in every picker; lower comes first, ties break on name */
    #[ORM\Column(name: 'sort_order', options: ['default' => self::SORT_ORDER_DEFAULT])]
    #[Assert\Range(min: 0, max: 9999)]
    private int $sortOrder = self::SORT_ORDER_DEFAULT;

    /** @var Collection<int, Department> */
    #[ORM\ManyToMany(targetEntity: Department::class, mappedBy: 'volunteerTypes')]
    private Collection $departments;

    /** @var Collection<int, Certification> */
    #[ORM\ManyToMany(targetEntity: Certification::class)]
    #[ORM\JoinTable(name: 'volunteer_type_certifications')]
    private Collection $certifications;

    /** @var Collection<int, VolunteerTypeContact> */
    #[ORM\OneToMany(mappedBy: 'volunteerType', targetEntity: VolunteerTypeContact::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contacts;

    public function __construct(string $name)
    {
        $this->uuid = Uuid::v4();
        $this->name = $name;
        $this->departments = new ArrayCollection();
        $this->certifications = new ArrayCollection();
        $this->contacts = new ArrayCollection();
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

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;

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

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): static
    {
        $this->contactPhone = $contactPhone;

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

    public function isDepartmentOnly(): bool
    {
        return $this->departmentOnly;
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    public function setGlobal(bool $global): static
    {
        $this->global = $global;

        return $this;
    }

    public function setDepartmentOnly(bool $departmentOnly): static
    {
        $this->departmentOnly = $departmentOnly;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /** "Requires Introduction" is the restricted-membership flag. */
    public function isRequiresIntroduction(): bool
    {
        return $this->restricted;
    }

    /** @return Collection<int, Department> */
    public function getDepartments(): Collection
    {
        return $this->departments;
    }

    /** @return Collection<int, Certification> */
    public function getCertifications(): Collection
    {
        return $this->certifications;
    }

    public function addCertification(Certification $certification): static
    {
        if (!$this->certifications->contains($certification)) {
            $this->certifications->add($certification);
        }

        return $this;
    }

    public function removeCertification(Certification $certification): static
    {
        $this->certifications->removeElement($certification);

        return $this;
    }

    /** @return Collection<int, VolunteerTypeContact> */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(VolunteerTypeContact $contact): static
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setVolunteerType($this);
        }

        return $this;
    }

    public function removeContact(VolunteerTypeContact $contact): static
    {
        $this->contacts->removeElement($contact);

        return $this;
    }

    /**
     * Enforce the flag interdependencies (also mirrored in the UI).
     */
    #[Assert\Callback]
    public function validateFlags(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->departmentOnly && !$this->staffOnly) {
            $context->buildViolation('"Department only" requires "Staff only".')->atPath('departmentOnly')->addViolation();
        }

        if ($this->global) {
            if ($this->departmentOnly) {
                $context->buildViolation('A global type belongs to every department, so it cannot be "Department only".')->atPath('departmentOnly')->addViolation();
            }
            if (!$this->departments->isEmpty()) {
                $context->buildViolation(\sprintf(
                    'Remove this type from %s before making it global; a global type cannot be claimed by a department.',
                    implode(', ', array_map(static fn (Department $d): string => $d->getName(), $this->departments->toArray())),
                ))->atPath('global')->addViolation();
            }
        }

        if (!$this->staffOnly) {
            if ($this->departmentOnly) {
                $context->buildViolation('Non-staff types cannot be department only.')->atPath('departmentOnly')->addViolation();
            }
            if ($this->hideOnShiftView) {
                $context->buildViolation('Non-staff types cannot be hidden from the shift view.')->atPath('hideOnShiftView')->addViolation();
            }
            if (!$this->showOnDashboard) {
                $context->buildViolation('Non-staff types must be shown on the dashboard.')->atPath('showOnDashboard')->addViolation();
            }
        } else {
            if (!$this->hideOnShiftView) {
                $context->buildViolation('Staff-only types must be hidden from the shift view.')->atPath('hideOnShiftView')->addViolation();
            }
            if ($this->showOnDashboard) {
                $context->buildViolation('Staff-only types must not be shown on the dashboard.')->atPath('showOnDashboard')->addViolation();
            }
        }
    }
}
