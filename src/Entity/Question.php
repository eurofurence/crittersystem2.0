<?php

namespace App\Entity;

use App\Repository\QuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A user-submitted question answered by an admin. A soft edit lock
 * prevents two admins answering at once; it auto-releases after 30 minutes
 */
#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'questions')]
#[ORM\HasLifecycleCallbacks]
class Question
{
    public const LOCK_TTL_MINUTES = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $text;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answer = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'answerer_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $answerer = null;

    #[ORM\Column(name: 'answered_at', nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'locked_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $lockedBy = null;

    #[ORM\Column(name: 'locked_at', nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?User $user, string $text)
    {
        $this->user = $user;
        $this->text = $text;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    public function setAnswer(?string $answer): static
    {
        $this->answer = $answer;

        return $this;
    }

    public function isAnswered(): bool
    {
        return $this->answer !== null && $this->answer !== '';
    }

    public function getAnswerer(): ?User
    {
        return $this->answerer;
    }

    public function setAnswerer(?User $answerer): static
    {
        $this->answerer = $answerer;

        return $this;
    }

    public function getAnsweredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function setAnsweredAt(?\DateTimeImmutable $answeredAt): static
    {
        $this->answeredAt = $answeredAt;

        return $this;
    }

    public function getLockedBy(): ?User
    {
        return $this->lockedBy;
    }

    public function setLockedBy(?User $lockedBy): static
    {
        $this->lockedBy = $lockedBy;

        return $this;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeImmutable $lockedAt): static
    {
        $this->lockedAt = $lockedAt;

        return $this;
    }

    /** Whether the lock is currently held by someone other than $user (and still fresh). */
    public function isLockedByOther(User $user): bool
    {
        if ($this->lockedBy === null || $this->lockedAt === null || $this->lockedBy === $user) {
            return false;
        }

        $expiry = $this->lockedAt->modify(\sprintf('+%d minutes', self::LOCK_TTL_MINUTES));

        return new \DateTimeImmutable() < $expiry;
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
