<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users_settings')]
class Settings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'settings', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 10)]
    private string $language = 'en_US';

    /** Slug of the user's preferred theme (see App\Theme\ThemeCatalog), or null = use admin default. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $theme = null;

    #[ORM\Column(name: 'email_human')]
    private bool $emailHuman = false;

    #[ORM\Column(name: 'email_messages')]
    private bool $emailMessages = false;

    #[ORM\Column(name: 'email_goodie')]
    private bool $emailGoodie = false;

    #[ORM\Column(name: 'email_shiftinfo')]
    private bool $emailShiftinfo = false;

    #[ORM\Column(name: 'email_news')]
    private bool $emailNews = false;

    #[ORM\Column(name: 'mobile_show')]
    private bool $mobileShow = false;

    /** @var array<string, array<string, mixed>>|null */
    #[ORM\Column(name: 'shift_filters', type: Types::JSON, nullable: true)]
    private ?array $shiftFilters = null;

    /**
     * Minutes before a shift a reminder should be created. Null
     * means "use the admin-configured system default".
     */
    #[ORM\Column(name: 'notification_reminder_lead', nullable: true)]
    private ?int $notificationReminderLead = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getNotificationReminderLead(): ?int
    {
        return $this->notificationReminderLead;
    }

    public function setNotificationReminderLead(?int $minutes): static
    {
        $this->notificationReminderLead = $minutes;

        return $this;
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

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function setTheme(?string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function isEmailHuman(): bool
    {
        return $this->emailHuman;
    }

    public function setEmailHuman(bool $emailHuman): static
    {
        $this->emailHuman = $emailHuman;

        return $this;
    }

    public function isEmailMessages(): bool
    {
        return $this->emailMessages;
    }

    public function setEmailMessages(bool $emailMessages): static
    {
        $this->emailMessages = $emailMessages;

        return $this;
    }

    public function isEmailGoodie(): bool
    {
        return $this->emailGoodie;
    }

    public function setEmailGoodie(bool $emailGoodie): static
    {
        $this->emailGoodie = $emailGoodie;

        return $this;
    }

    public function isEmailShiftinfo(): bool
    {
        return $this->emailShiftinfo;
    }

    public function setEmailShiftinfo(bool $emailShiftinfo): static
    {
        $this->emailShiftinfo = $emailShiftinfo;

        return $this;
    }

    public function isEmailNews(): bool
    {
        return $this->emailNews;
    }

    public function setEmailNews(bool $emailNews): static
    {
        $this->emailNews = $emailNews;

        return $this;
    }

    /**
     * The filters this user last chose on each shift screen, keyed by screen.
     *
     * Free-form on the way in and whitelisted on the way out by the service that owns it, so a
     * value that stops being valid, such as a location since deleted, simply drops rather than
     * reaching a query.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getShiftFilters(): array
    {
        return $this->shiftFilters ?? [];
    }

    /** @param array<string, array<string, mixed>> $shiftFilters */
    public function setShiftFilters(array $shiftFilters): static
    {
        $this->shiftFilters = $shiftFilters === [] ? null : $shiftFilters;

        return $this;
    }

    public function isMobileShow(): bool
    {
        return $this->mobileShow;
    }

    public function setMobileShow(bool $mobileShow): static
    {
        $this->mobileShow = $mobileShow;

        return $this;
    }
}
