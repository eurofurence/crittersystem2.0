<?php

namespace App\Entity;

use App\Repository\ShiftEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A volunteer's sign-up for a shift under a particular volunteer type.
 * A user may hold at most one entry per shift (see the unique constraint).
 */
#[ORM\Entity(repositoryClass: ShiftEntryRepository::class)]
#[ORM\Table(name: 'shift_entries')]
#[ORM\UniqueConstraint(name: 'uniq_shift_user', columns: ['shift_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class ShiftEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shift::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'shift_id', nullable: false, onDelete: 'CASCADE')]
    private Shift $shift;

    #[ORM\ManyToOne(targetEntity: VolunteerType::class)]
    #[ORM\JoinColumn(name: 'volunteer_type_id', nullable: false, onDelete: 'CASCADE')]
    private VolunteerType $volunteerType;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'user_comment', type: Types::TEXT, nullable: true)]
    private ?string $userComment = null;

    #[ORM\Column]
    private bool $noshow = false;

    #[ORM\Column(name: 'noshow_comment', type: Types::TEXT, nullable: true)]
    private ?string $noshowComment = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Shift $shift, VolunteerType $volunteerType, User $user)
    {
        $this->shift = $shift;
        $this->volunteerType = $volunteerType;
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShift(): Shift
    {
        return $this->shift;
    }

    public function getVolunteerType(): VolunteerType
    {
        return $this->volunteerType;
    }

    public function setVolunteerType(VolunteerType $volunteerType): static
    {
        $this->volunteerType = $volunteerType;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUserComment(): ?string
    {
        return $this->userComment;
    }

    public function setUserComment(?string $userComment): static
    {
        $this->userComment = $userComment;

        return $this;
    }

    public function isNoshow(): bool
    {
        return $this->noshow;
    }

    public function setNoshow(bool $noshow): static
    {
        $this->noshow = $noshow;

        return $this;
    }

    public function getNoshowComment(): ?string
    {
        return $this->noshowComment;
    }

    public function setNoshowComment(?string $noshowComment): static
    {
        $this->noshowComment = $noshowComment;

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
