<?php

namespace App\Entity;

use App\Repository\NewsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NewsRepository::class)]
#[ORM\Table(name: 'news')]
#[ORM\HasLifecycleCallbacks]
class News
{
    /** Marker that splits a short preview from the full article body */
    public const MORE_TAG = '[more]';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $text;

    #[ORM\Column(name: 'is_meeting')]
    private bool $isMeeting = false;

    #[ORM\Column(name: 'is_pinned')]
    private bool $isPinned = false;

    #[ORM\Column(name: 'is_highlighted')]
    private bool $isHighlighted = false;

    #[ORM\Column(name: 'staff_only')]
    private bool $staffOnly = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, NewsComment> */
    #[ORM\OneToMany(mappedBy: 'news', targetEntity: NewsComment::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
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

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    /** Short preview (text before the [more] tag), or the whole text when absent. */
    public function getPreview(): string
    {
        $pos = stripos($this->text, self::MORE_TAG);

        return $pos === false ? $this->text : rtrim(substr($this->text, 0, $pos));
    }

    /** Full text with the [more] marker stripped out. */
    public function getFullText(): string
    {
        return trim(str_ireplace(self::MORE_TAG, '', $this->text));
    }

    public function hasMore(): bool
    {
        return stripos($this->text, self::MORE_TAG) !== false;
    }

    public function isMeeting(): bool
    {
        return $this->isMeeting;
    }

    public function setIsMeeting(bool $isMeeting): static
    {
        $this->isMeeting = $isMeeting;

        return $this;
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function setIsPinned(bool $isPinned): static
    {
        $this->isPinned = $isPinned;

        return $this;
    }

    public function isHighlighted(): bool
    {
        return $this->isHighlighted;
    }

    public function setIsHighlighted(bool $isHighlighted): static
    {
        $this->isHighlighted = $isHighlighted;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @return Collection<int, NewsComment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }
}
