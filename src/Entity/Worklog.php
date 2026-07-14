<?php

namespace App\Entity;

use App\Repository\WorklogRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A manual hour entry for work not tracked through a formal shift,
 * recorded by a manager on behalf of a user.
 */
#[ORM\Entity(repositoryClass: WorklogRepository::class)]
#[ORM\Table(name: 'worklogs')]
#[ORM\HasLifecycleCallbacks]
class Worklog
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The manager who recorded the entry. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creator_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $creator = null;

    #[ORM\Column]
    #[Assert\NotNull]
    private float $hours = 0.0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'worked_at')]
    #[Assert\NotNull]
    private \DateTimeImmutable $workedAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user)
    {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->workedAt = new \DateTimeImmutable();
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

    public function getCreator(): ?User
    {
        return $this->creator;
    }

    public function setCreator(?User $creator): static
    {
        $this->creator = $creator;

        return $this;
    }

    public function getHours(): float
    {
        return $this->hours;
    }

    public function setHours(float $hours): static
    {
        $this->hours = $hours;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getWorkedAt(): \DateTimeImmutable
    {
        return $this->workedAt;
    }

    public function setWorkedAt(\DateTimeImmutable $workedAt): static
    {
        $this->workedAt = $workedAt;

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
