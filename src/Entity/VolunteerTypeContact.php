<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One of a Volunteer Type's contact points: name, phone, Telegram
 * handle and email. Only fields with values are rendered.
 */
#[ORM\Entity]
#[ORM\Table(name: 'volunteer_type_contacts')]
class VolunteerTypeContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VolunteerType::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(name: 'volunteer_type_id', nullable: false, onDelete: 'CASCADE')]
    private ?VolunteerType $volunteerType = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $name = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $phone = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $telegram = null;

    #[ORM\Column(length: 254, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 254)]
    private ?string $email = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVolunteerType(): ?VolunteerType
    {
        return $this->volunteerType;
    }

    public function setVolunteerType(?VolunteerType $volunteerType): static
    {
        $this->volunteerType = $volunteerType;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getTelegram(): ?string
    {
        return $this->telegram;
    }

    /** The handle is stored with a leading '@' whatever the caller passes. */
    public function setTelegram(?string $telegram): static
    {
        $this->telegram = $telegram !== null && $telegram !== '' ? '@'.ltrim($telegram, '@') : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }
}
