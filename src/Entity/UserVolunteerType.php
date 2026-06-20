<?php

namespace App\Entity;

use App\Repository\UserVolunteerTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Membership of a user in a volunteer type. When the type is restricted,
 * a new membership is unconfirmed (confirmedBy === null) until a supporter or
 * admin confirms it. A supporter can confirm others and manage the member list.
 */
#[ORM\Entity(repositoryClass: UserVolunteerTypeRepository::class)]
#[ORM\Table(name: 'user_volunteer_types')]
#[ORM\UniqueConstraint(name: 'uniq_user_volunteer_type', columns: ['user_id', 'volunteer_type_id'])]
#[ORM\HasLifecycleCallbacks]
class UserVolunteerType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: VolunteerType::class)]
    #[ORM\JoinColumn(name: 'volunteer_type_id', nullable: false, onDelete: 'CASCADE')]
    private VolunteerType $volunteerType;

    /** Who confirmed the membership; null means unconfirmed. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'confirmed_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedBy = null;

    /** Supporters can confirm/manage other members of this type. */
    #[ORM\Column]
    private bool $supporter = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, VolunteerType $volunteerType)
    {
        $this->user = $user;
        $this->volunteerType = $volunteerType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVolunteerType(): VolunteerType
    {
        return $this->volunteerType;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): static
    {
        $this->confirmedBy = $confirmedBy;

        return $this;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmedBy !== null;
    }

    public function isSupporter(): bool
    {
        return $this->supporter;
    }

    public function setSupporter(bool $supporter): static
    {
        $this->supporter = $supporter;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
