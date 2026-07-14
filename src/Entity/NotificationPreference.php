<?php

namespace App\Entity;

use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's delivery preference for one notification category.
 * In-app is forced on for system categories regardless of the stored value;
 * this is resolved by {@see \App\Service\Notification\NotificationService}.
 */
#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_notif_pref_user_category', columns: ['user_id', 'category'])]
class NotificationPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 48)]
    private string $category;

    #[ORM\Column(name: 'in_app')]
    private bool $inApp = true;

    #[ORM\Column]
    private bool $email = false;

    #[ORM\Column]
    private bool $telegram = false;

    public function __construct(User $user, string $category)
    {
        $this->user = $user;
        $this->category = $category;
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

    public function isInApp(): bool
    {
        return $this->inApp;
    }

    public function setInApp(bool $inApp): static
    {
        $this->inApp = $inApp;

        return $this;
    }

    public function isEmail(): bool
    {
        return $this->email;
    }

    public function setEmail(bool $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function isTelegram(): bool
    {
        return $this->telegram;
    }

    public function setTelegram(bool $telegram): static
    {
        $this->telegram = $telegram;

        return $this;
    }
}
