<?php

namespace App\Entity;

use App\Repository\UserConsentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's GDPR consent record: the mandatory data-processing consent plus the
 * optional visibility opt-ins (full name, email, phone to managers/shift
 * managers). Notification opt-ins live on {@see Settings}; this entity is the
 * record that consent was given, when and in which language.
 */
#[ORM\Entity(repositoryClass: UserConsentRepository::class)]
#[ORM\Table(name: 'user_consents')]
#[ORM\HasLifecycleCallbacks]
class UserConsent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'consent', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'data_processing')]
    private bool $dataProcessing = false;

    #[ORM\Column(name: 'full_name_visible')]
    private bool $fullNameVisible = false;

    #[ORM\Column(name: 'email_visible')]
    private bool $emailVisible = false;

    #[ORM\Column(name: 'phone_visible')]
    private bool $phoneVisible = false;

    #[ORM\Column(name: 'telegram_visible')]
    private bool $telegramVisible = false;

    #[ORM\Column(name: 'consent_locale', length: 10, nullable: true)]
    private ?string $consentLocale = null;

    #[ORM\Column(name: 'consented_at', nullable: true)]
    private ?\DateTimeImmutable $consentedAt = null;

    /**
     * Provenance for the visibility opt-ins, kept separate from the
     * data-processing grant above: when the volunteer last set who may see their
     * contact details, and under which privacy-notice version.
     */
    #[ORM\Column(name: 'visibility_consented_at', nullable: true)]
    private ?\DateTimeImmutable $visibilityConsentedAt = null;

    #[ORM\Column(name: 'visibility_notice_version', length: 40, nullable: true)]
    private ?string $visibilityNoticeVersion = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function hasDataProcessing(): bool
    {
        return $this->dataProcessing;
    }

    /** Record that the mandatory data-processing consent was given. */
    public function grantDataProcessing(?string $locale): static
    {
        $this->dataProcessing = true;
        $this->consentLocale = $locale;
        $this->consentedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isFullNameVisible(): bool
    {
        return $this->fullNameVisible;
    }

    public function setFullNameVisible(bool $v): static
    {
        $this->fullNameVisible = $v;

        return $this;
    }

    public function isEmailVisible(): bool
    {
        return $this->emailVisible;
    }

    public function setEmailVisible(bool $v): static
    {
        $this->emailVisible = $v;

        return $this;
    }

    public function isPhoneVisible(): bool
    {
        return $this->phoneVisible;
    }

    public function setPhoneVisible(bool $v): static
    {
        $this->phoneVisible = $v;

        return $this;
    }

    public function isTelegramVisible(): bool
    {
        return $this->telegramVisible;
    }

    public function setTelegramVisible(bool $v): static
    {
        $this->telegramVisible = $v;

        return $this;
    }

    /** Whether at least one reachable channel is shared, so a manager can contact the volunteer. */
    public function hasVisibleChannel(): bool
    {
        return $this->emailVisible || $this->phoneVisible || $this->telegramVisible;
    }

    /**
     * Stamp when and under which notice version the visibility opt-ins were last
     * set. Call after changing the flags, in onboarding and on the edit screen.
     */
    public function stampVisibilityProvenance(?string $noticeVersion): static
    {
        $this->visibilityConsentedAt = new \DateTimeImmutable();
        $this->visibilityNoticeVersion = $noticeVersion;

        return $this;
    }

    public function getVisibilityConsentedAt(): ?\DateTimeImmutable
    {
        return $this->visibilityConsentedAt;
    }

    public function getVisibilityNoticeVersion(): ?string
    {
        return $this->visibilityNoticeVersion;
    }

    public function getConsentLocale(): ?string
    {
        return $this->consentLocale;
    }

    public function getConsentedAt(): ?\DateTimeImmutable
    {
        return $this->consentedAt;
    }
}
