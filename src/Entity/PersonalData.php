<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users_personal_data')]
class PersonalData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'personalData', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'first_name', length: 64, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', length: 64, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $pronoun = null;

    #[ORM\Column(name: 'shirt_size', length: 4, nullable: true)]
    private ?string $shirtSize = null;

    #[ORM\Column(name: 'badge_number', nullable: true)]
    private ?int $badgeNumber = null;

    #[ORM\Column(name: 'planned_arrival_date', nullable: true)]
    private ?\DateTimeImmutable $plannedArrivalDate = null;

    #[ORM\Column(name: 'planned_departure_date', nullable: true)]
    private ?\DateTimeImmutable $plannedDepartureDate = null;

    /** Storage-relative key of the profile picture (see App\Storage\FileStorage); null = fallback monogram. */
    #[ORM\Column(name: 'avatar_path', length: 255, nullable: true)]
    private ?string $avatarPath = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): static
    {
        $this->avatarPath = $avatarPath;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getPronoun(): ?string
    {
        return $this->pronoun;
    }

    public function setPronoun(?string $pronoun): static
    {
        $this->pronoun = $pronoun;

        return $this;
    }

    public function getShirtSize(): ?string
    {
        return $this->shirtSize;
    }

    public function setShirtSize(?string $shirtSize): static
    {
        $this->shirtSize = $shirtSize;

        return $this;
    }

    public function getBadgeNumber(): ?int
    {
        return $this->badgeNumber;
    }

    public function setBadgeNumber(?int $badgeNumber): static
    {
        $this->badgeNumber = $badgeNumber;

        return $this;
    }

    public function getPlannedArrivalDate(): ?\DateTimeImmutable
    {
        return $this->plannedArrivalDate;
    }

    public function setPlannedArrivalDate(?\DateTimeImmutable $plannedArrivalDate): static
    {
        $this->plannedArrivalDate = $plannedArrivalDate;

        return $this;
    }

    public function getPlannedDepartureDate(): ?\DateTimeImmutable
    {
        return $this->plannedDepartureDate;
    }

    public function setPlannedDepartureDate(?\DateTimeImmutable $plannedDepartureDate): static
    {
        $this->plannedDepartureDate = $plannedDepartureDate;

        return $this;
    }
}
