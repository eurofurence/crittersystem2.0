<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * An in-app notification delivered to a single user. Email/Telegram
 * fan-out is handled by {@see \App\Service\Notification\NotificationService};
 * this row is the durable in-app record with read state and an optional action.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notification_user_read', columns: ['user_id', 'read_at'])]
class Notification
{
    use HasPublicUuid;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 48)]
    private string $category;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(name: 'action_url', length: 512, nullable: true)]
    private ?string $actionUrl = null;

    #[ORM\Column]
    private bool $mandatory = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'read_at', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(User $user, string $category, string $title, string $message, ?string $actionUrl = null, bool $mandatory = false)
    {
        $this->uuid = Uuid::v4();
        $this->user = $user;
        $this->category = $category;
        $this->title = $title;
        $this->message = $message;
        $this->actionUrl = $actionUrl;
        $this->mandatory = $mandatory;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getActionUrl(): ?string
    {
        return $this->actionUrl;
    }

    public function isMandatory(): bool
    {
        return $this->mandatory;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(): static
    {
        $this->readAt ??= new \DateTimeImmutable();

        return $this;
    }
}
