<?php

namespace App\Entity;

use App\Repository\InternalNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Staff-only operational note. Optionally about a particular user
 * and/or duty area (Department), grouped by category.
 */
#[ORM\Entity(repositoryClass: InternalNoteRepository::class)]
#[ORM\Table(name: 'internal_notes')]
#[ORM\HasLifecycleCallbacks]
class InternalNote
{
    public const CATEGORIES = ['general', 'incident', 'handover', 'maintenance', 'praise'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /** The user this note is about, if any. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $subjectUser = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $content;

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: self::CATEGORIES)]
    private string $category = 'general';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(?User $author, string $content)
    {
        $this->uuid = Uuid::v4();
        $this->author = $author;
        $this->content = $content;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getSubjectUser(): ?User
    {
        return $this->subjectUser;
    }

    public function setSubjectUser(?User $subjectUser): static
    {
        $this->subjectUser = $subjectUser;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

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
