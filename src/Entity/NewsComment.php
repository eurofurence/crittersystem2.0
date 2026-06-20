<?php

namespace App\Entity;

use App\Repository\NewsCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NewsCommentRepository::class)]
#[ORM\Table(name: 'news_comments')]
#[ORM\HasLifecycleCallbacks]
class NewsComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: News::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'news_id', nullable: false, onDelete: 'CASCADE')]
    private News $news;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $text;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(News $news, ?User $author, string $text)
    {
        $this->news = $news;
        $this->author = $author;
        $this->text = $text;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNews(): News
    {
        return $this->news;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
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
