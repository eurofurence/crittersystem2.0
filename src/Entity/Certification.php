<?php

namespace App\Entity;

use App\Repository\CertificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CertificationRepository::class)]
#[ORM\Table(name: 'certifications')]
#[UniqueEntity('title')]
class Certification
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
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'contact_person', length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $contactPerson = null;

    #[ORM\Column(name: 'contact_email', length: 254, nullable: true)]
    #[Assert\Length(max: 254)]
    #[Assert\Email]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $location = null;

    /** Never expires. */
    #[ORM\Column(name: 'is_perpetual')]
    private bool $isPerpetual = false;

    /** Days until expiry, calculated from the certification date (null when perpetual). */
    #[ORM\Column(name: 'validity_period_days', nullable: true)]
    #[Assert\Positive]
    private ?int $validityPeriodDays = null;

    /** Users may self-confirm this certification. */
    #[ORM\Column(name: 'allow_self_confirmation')]
    private bool $allowSelfConfirmation = false;

    /** Visible only to staff users. */
    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    /** Available for application. */
    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    public function __construct(string $title)
    {
        $this->uuid = Uuid::v4();
        $this->title = $title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;

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

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function isPerpetual(): bool
    {
        return $this->isPerpetual;
    }

    public function setIsPerpetual(bool $isPerpetual): static
    {
        $this->isPerpetual = $isPerpetual;

        return $this;
    }

    public function getValidityPeriodDays(): ?int
    {
        return $this->validityPeriodDays;
    }

    public function setValidityPeriodDays(?int $validityPeriodDays): static
    {
        $this->validityPeriodDays = $validityPeriodDays;

        return $this;
    }

    public function isAllowSelfConfirmation(): bool
    {
        return $this->allowSelfConfirmation;
    }

    public function setAllowSelfConfirmation(bool $allowSelfConfirmation): static
    {
        $this->allowSelfConfirmation = $allowSelfConfirmation;

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
