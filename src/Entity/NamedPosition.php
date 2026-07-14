<?php

namespace App\Entity;

use App\Repository\NamedPositionRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An actual title/role held by users, e.g. "Hand #1" or "FOH".
 * Distinct positions are meaningful, not anonymous capacity slots. Name,
 * description, order, group and capacity are configurable; a position may
 * require Volunteer Type(s) and certifications and define self-application.
 */
#[ORM\Entity(repositoryClass: NamedPositionRepository::class)]
#[ORM\Table(name: 'named_positions')]
class NamedPosition
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PositionGroup::class, inversedBy: 'positions')]
    #[ORM\JoinColumn(name: 'position_group_id', nullable: false, onDelete: 'CASCADE')]
    private PositionGroup $group;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'display_order', type: Types::INTEGER)]
    private int $displayOrder = 0;

    /** Occupants allowed. 1 = an exclusive named role; >1 = a multi-occupant slot. */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive]
    private int $capacity = 1;

    /** Whether eligible staff may self-apply, or only managers assign. */
    #[ORM\Column(name: 'self_applicable')]
    private bool $selfApplicable = false;

    /** @var Collection<int, VolunteerType> */
    #[ORM\ManyToMany(targetEntity: VolunteerType::class)]
    #[ORM\JoinTable(name: 'named_position_volunteer_types')]
    private Collection $requiredVolunteerTypes;

    /** @var Collection<int, Certification> */
    #[ORM\ManyToMany(targetEntity: Certification::class)]
    #[ORM\JoinTable(name: 'named_position_certifications')]
    private Collection $requiredCertifications;

    public function __construct(PositionGroup $group, string $name)
    {
        $this->uuid = Uuid::v4();
        $this->group = $group;
        $this->name = $name;
        $this->requiredVolunteerTypes = new ArrayCollection();
        $this->requiredCertifications = new ArrayCollection();
        $group->addPosition($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): PositionGroup
    {
        return $this->group;
    }

    public function setGroup(PositionGroup $group): static
    {
        $this->group = $group;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function setCapacity(int $capacity): static
    {
        $this->capacity = $capacity;

        return $this;
    }

    public function allowsMultiple(): bool
    {
        return $this->capacity > 1;
    }

    public function isSelfApplicable(): bool
    {
        return $this->selfApplicable;
    }

    public function setSelfApplicable(bool $selfApplicable): static
    {
        $this->selfApplicable = $selfApplicable;

        return $this;
    }

    /** @return Collection<int, VolunteerType> */
    public function getRequiredVolunteerTypes(): Collection
    {
        return $this->requiredVolunteerTypes;
    }

    public function addRequiredVolunteerType(VolunteerType $type): static
    {
        if (!$this->requiredVolunteerTypes->contains($type)) {
            $this->requiredVolunteerTypes->add($type);
        }

        return $this;
    }

    public function removeRequiredVolunteerType(VolunteerType $type): static
    {
        $this->requiredVolunteerTypes->removeElement($type);

        return $this;
    }

    /** @return Collection<int, Certification> */
    public function getRequiredCertifications(): Collection
    {
        return $this->requiredCertifications;
    }

    public function addRequiredCertification(Certification $certification): static
    {
        if (!$this->requiredCertifications->contains($certification)) {
            $this->requiredCertifications->add($certification);
        }

        return $this;
    }

    public function removeRequiredCertification(Certification $certification): static
    {
        $this->requiredCertifications->removeElement($certification);

        return $this;
    }
}
