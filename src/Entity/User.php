<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Entity\Concern\HasPublicUuid;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use HasPublicUuid;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SSO = 'sso';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $name;

    #[ORM\Column(length: 254, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(name: 'api_key', length: 32, unique: true)]
    private string $apiKey;

    #[ORM\Column(name: 'last_login_at', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** How the account was created: 'manual' (admin/invite) or 'sso'. */
    #[ORM\Column(name: 'account_source', length: 16, options: ['default' => 'manual'])]
    private string $accountSource = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'sso_user_id', length: 128, nullable: true)]
    private ?string $ssoUserId = null;

    #[ORM\Column(name: 'sso_provider', length: 64, nullable: true)]
    private ?string $ssoProvider = null;

    #[ORM\Column(name: 'onboarding_completed', options: ['default' => false])]
    private bool $onboardingCompleted = false;

    #[ORM\Column(name: 'onboarding_completed_at', nullable: true)]
    private ?\DateTimeImmutable $onboardingCompletedAt = null;

    /**
     * Set when an administrator wants the user to run through onboarding again.
     * The reset is applied at their next sign-in, not here: OnboardingGateSubscriber
     * reads the completed flag on every request, so clearing it directly would throw
     * anyone already signed in into the wizard mid-session.
     */
    #[ORM\Column(name: 'onboarding_reset_requested_at', nullable: true)]
    private ?\DateTimeImmutable $onboardingResetRequestedAt = null;

    /** Opaque token for one-click, unauthenticated unsubscribe links. */
    #[ORM\Column(name: 'unsubscribe_token', length: 32, unique: true, nullable: true)]
    private ?string $unsubscribeToken = null;

    /**
     * No-shows before this instant do not count toward the automatic ban
     * threshold (reset on unban). Null = count all no-shows.
     */
    #[ORM\Column(name: 'no_show_baseline_at', nullable: true)]
    private ?\DateTimeImmutable $noShowBaselineAt = null;

    #[ORM\Column(name: 'totp_secret', type: 'encrypted_string', nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(name: 'two_factor_enabled', options: ['default' => false])]
    private bool $twoFactorEnabled = false;

    /** When true (set by an admin for a sub-admin), 2FA is mandatory for this user. */
    #[ORM\Column(name: 'two_factor_required', options: ['default' => false])]
    private bool $twoFactorRequired = false;

    /** Remaining one-time recovery codes, encrypted JSON. */
    #[ORM\Column(name: 'backup_codes', type: 'encrypted_string', nullable: true)]
    private ?string $backupCodes = null;

    /** Telegram numeric chat/user id (PII - masked from sub-admins). */
    #[ORM\Column(name: 'telegram_id', length: 64, nullable: true)]
    private ?string $telegramId = null;

    #[ORM\Column(name: 'telegram_handle', length: 64, nullable: true)]
    private ?string $telegramHandle = null;

    /**
     * Opaque credential the bot presents (X-Acting-Token) to act as this user.
     * Minted fresh on every link and nulled on unlink, so a revoked link's token
     * is dead immediately and a re-link issues a new one - the bot cannot act on
     * a stale link even if its own local record survives. Never PII-masked away:
     * it is a secret, so it is never rendered or returned to any client but the
     * bot at link time.
     */
    #[ORM\Column(name: 'telegram_acting_token', length: 128, nullable: true, unique: true)]
    private ?string $telegramActingToken = null;

    #[ORM\Column(name: 'telegram_linked_at', nullable: true)]
    private ?\DateTimeImmutable $telegramLinkedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: PersonalData::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?PersonalData $personalData = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Contact::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?Contact $contact = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Settings::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?Settings $settings = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: State::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?State $state = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: UserConsent::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?UserConsent $consent = null;

    /** @var Collection<int, UserGroupAssignment> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserGroupAssignment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $groupAssignments;

    /** @var Collection<int, Badge> */
    #[ORM\ManyToMany(targetEntity: Badge::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'users_badges')]
    private Collection $badges;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->groupAssignments = new ArrayCollection();
        $this->badges = new ArrayCollection();
    }

    /**
     * Keep the session payload small and free of the relation graph; the hashed
     * password is still stored so that changing it invalidates existing sessions.
     */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name ?? null, 'password' => $this->password ?? null];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? '';
        $this->password = $data['password'] ?? '';
        $this->groupAssignments = new ArrayCollection();
        $this->badges = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getAccountSource(): string
    {
        return $this->accountSource;
    }

    public function setAccountSource(string $accountSource): static
    {
        $this->accountSource = $accountSource;

        return $this;
    }

    public function isSsoManaged(): bool
    {
        return $this->accountSource === self::SOURCE_SSO;
    }

    /** Username is never user-editable; SSO owns it, manual accounts keep it fixed. */
    public function canEditUsername(): bool
    {
        return false;
    }

    /** Email is never user-editable (SSO-owned, or fixed for manual accounts). */
    public function canEditEmail(): bool
    {
        return false;
    }

    /** Manual accounts may change their full name; SSO accounts may not. */
    public function canEditFullName(): bool
    {
        return !$this->isSsoManaged();
    }

    public function getSsoUserId(): ?string
    {
        return $this->ssoUserId;
    }

    public function setSsoUserId(?string $ssoUserId): static
    {
        $this->ssoUserId = $ssoUserId;

        return $this;
    }

    public function getSsoProvider(): ?string
    {
        return $this->ssoProvider;
    }

    public function setSsoProvider(?string $ssoProvider): static
    {
        $this->ssoProvider = $ssoProvider;

        return $this;
    }

    public function isOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }

    public function getOnboardingCompletedAt(): ?\DateTimeImmutable
    {
        return $this->onboardingCompletedAt;
    }

    public function completeOnboarding(): static
    {
        $this->onboardingCompleted = true;
        $this->onboardingCompletedAt = new \DateTimeImmutable();

        return $this;
    }

    public function resetOnboarding(): static
    {
        $this->onboardingCompleted = false;
        $this->onboardingCompletedAt = null;
        $this->onboardingResetRequestedAt = null;

        return $this;
    }

    public function getOnboardingResetRequestedAt(): ?\DateTimeImmutable
    {
        return $this->onboardingResetRequestedAt;
    }

    public function isOnboardingResetPending(): bool
    {
        return $this->onboardingResetRequestedAt !== null;
    }

    /** Queue a re-run of onboarding, applied at the user's next sign-in. */
    public function requestOnboardingReset(): static
    {
        $this->onboardingResetRequestedAt = new \DateTimeImmutable();

        return $this;
    }

    public function cancelOnboardingReset(): static
    {
        $this->onboardingResetRequestedAt = null;

        return $this;
    }

    public function getNoShowBaselineAt(): ?\DateTimeImmutable
    {
        return $this->noShowBaselineAt;
    }

    public function setNoShowBaselineAt(?\DateTimeImmutable $noShowBaselineAt): static
    {
        $this->noShowBaselineAt = $noShowBaselineAt;

        return $this;
    }

    public function getUnsubscribeToken(): ?string
    {
        return $this->unsubscribeToken;
    }

    public function setUnsubscribeToken(?string $unsubscribeToken): static
    {
        $this->unsubscribeToken = $unsubscribeToken;

        return $this;
    }

    /** @return Collection<int, Badge> */
    public function getBadges(): Collection
    {
        return $this->badges;
    }

    public function addBadge(Badge $badge): static
    {
        if (!$this->badges->contains($badge)) {
            $this->badges->add($badge);
        }

        return $this;
    }

    public function removeBadge(Badge $badge): static
    {
        $this->badges->removeElement($badge);

        return $this;
    }

    public function hasBadge(Badge $badge): bool
    {
        return $this->badges->contains($badge);
    }

    /** The highest-priority position badge, or null if the user has none. */
    public function getPositionBadge(): ?Badge
    {
        $best = null;
        foreach ($this->badges as $badge) {
            if ($badge->isPosition() && ($best === null || $badge->getPriority() > $best->getPriority())) {
                $best = $badge;
            }
        }

        return $best;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;

        return $this;
    }

    public function isTwoFactorEnabled(): bool
    {
        return $this->twoFactorEnabled;
    }

    public function setTwoFactorEnabled(bool $enabled): static
    {
        $this->twoFactorEnabled = $enabled;

        return $this;
    }

    public function isTwoFactorRequired(): bool
    {
        return $this->twoFactorRequired;
    }

    public function setTwoFactorRequired(bool $required): static
    {
        $this->twoFactorRequired = $required;

        return $this;
    }

    /** Whether 2FA is mandatory for this user (global admins always; others by flag). */
    public function mustUseTwoFactor(): bool
    {
        return $this->twoFactorRequired || \in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    /** @return string[] remaining recovery codes */
    public function getBackupCodes(): array
    {
        if ($this->backupCodes === null || $this->backupCodes === '') {
            return [];
        }

        return json_decode($this->backupCodes, true) ?: [];
    }

    /** @param string[] $codes */
    public function setBackupCodes(array $codes): static
    {
        $this->backupCodes = $codes === [] ? null : json_encode(array_values($codes));

        return $this;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegramId;
    }

    public function getTelegramHandle(): ?string
    {
        return $this->telegramHandle;
    }

    public function getTelegramLinkedAt(): ?\DateTimeImmutable
    {
        return $this->telegramLinkedAt;
    }

    public function isTelegramLinked(): bool
    {
        return $this->telegramId !== null;
    }

    /**
     * The acting token is rotated on every link, so a token issued for an earlier link can never act
     * again. Even a re-link by the same account gets a fresh credential.
     */
    public function linkTelegram(string $telegramId, ?string $handle): static
    {
        $this->telegramId = $telegramId;
        $this->telegramHandle = $handle;
        $this->telegramLinkedAt = new \DateTimeImmutable();
        $this->telegramActingToken = bin2hex(random_bytes(32));

        return $this;
    }

    public function unlinkTelegram(): static
    {
        $this->telegramId = null;
        $this->telegramHandle = null;
        $this->telegramLinkedAt = null;
        $this->telegramActingToken = null;

        return $this;
    }

    public function getTelegramActingToken(): ?string
    {
        return $this->telegramActingToken;
    }

    public function getConsent(): ?UserConsent
    {
        return $this->consent;
    }

    public function setConsent(?UserConsent $consent): static
    {
        if ($consent !== null && $consent->getUser() !== $this) {
            $consent->setUser($this);
        }
        $this->consent = $consent;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

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

    public function getPersonalData(): ?PersonalData
    {
        return $this->personalData;
    }

    public function setPersonalData(?PersonalData $personalData): static
    {
        if ($personalData !== null && $personalData->getUser() !== $this) {
            $personalData->setUser($this);
        }
        $this->personalData = $personalData;

        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        if ($contact !== null && $contact->getUser() !== $this) {
            $contact->setUser($this);
        }
        $this->contact = $contact;

        return $this;
    }

    public function getSettings(): ?Settings
    {
        return $this->settings;
    }

    public function setSettings(?Settings $settings): static
    {
        if ($settings !== null && $settings->getUser() !== $this) {
            $settings->setUser($this);
        }
        $this->settings = $settings;

        return $this;
    }

    public function getState(): ?State
    {
        return $this->state;
    }

    public function setState(?State $state): static
    {
        if ($state !== null && $state->getUser() !== $this) {
            $state->setUser($this);
        }
        $this->state = $state;

        return $this;
    }

    /** @return Collection<int, UserGroupAssignment> */
    public function getGroupAssignments(): Collection
    {
        return $this->groupAssignments;
    }

    /**
     * Assignments that are currently in effect (not past their expiry).
     *
     * @return UserGroupAssignment[]
     */
    public function getActiveAssignments(): array
    {
        $now = new \DateTimeImmutable();

        return array_values(array_filter(
            $this->groupAssignments->toArray(),
            static fn (UserGroupAssignment $a): bool => $a->isActive($now),
        ));
    }

    /**
     * Distinct groups the user currently belongs to (active assignments only).
     *
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        $groups = new ArrayCollection();
        foreach ($this->getActiveAssignments() as $assignment) {
            if (!$groups->contains($assignment->getGroup())) {
                $groups->add($assignment->getGroup());
            }
        }

        return $groups;
    }

    /** Add an unscoped, permanent membership (no-op if already present). */
    public function addGroup(Group $group): static
    {
        foreach ($this->groupAssignments as $assignment) {
            if ($assignment->getGroup() === $group && $assignment->getDepartment() === null) {
                return $this;
            }
        }
        $this->groupAssignments->add(new UserGroupAssignment($this, $group));

        return $this;
    }

    /** Add a membership optionally scoped to a department and/or time-boxed. */
    public function assignGroup(Group $group, ?Department $department = null, ?\DateTimeImmutable $expiresAt = null): UserGroupAssignment
    {
        $assignment = new UserGroupAssignment($this, $group, $department, $expiresAt);
        $this->groupAssignments->add($assignment);

        return $assignment;
    }

    public function removeGroup(Group $group): static
    {
        foreach ($this->groupAssignments as $assignment) {
            if ($assignment->getGroup() === $group) {
                $this->groupAssignments->removeElement($assignment);
            }
        }

        return $this;
    }

    /**
     * Effective permission names: the union across the user's active groups.
     *
     * @return string[]
     */
    public function getPrivilegeNames(): array
    {
        $names = [];
        foreach ($this->getGroups() as $group) {
            foreach ($group->getPrivileges() as $privilege) {
                $names[$privilege->getName()] = true;
            }
        }

        return array_keys($names);
    }

    public function hasPrivilege(string $name): bool
    {
        return \in_array($name, $this->getPrivilegeNames(), true);
    }

    /** @param string[] $names */
    public function hasAnyPrivilege(array $names): bool
    {
        return [] !== array_intersect($names, $this->getPrivilegeNames());
    }

    /**
     * Coarse roles for firewall/access_control gating, taken from the user's
     * active groups. Fine-grained checks go through the privilege voter.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->getGroups() as $group) {
            if ($group->getRole() !== null) {
                $roles[] = $group->getRole();
            }
        }

        return array_values(array_unique($roles));
    }

    /** True for staff, sub-admins and admins (used to gate staff-only content). */
    public function isStaff(): bool
    {
        return array_intersect(['ROLE_STAFF', 'ROLE_SUBADMIN', 'ROLE_ADMIN'], $this->getRoles()) !== [];
    }

    /** Member of the Info Desk group (support-conversation claiming). */
    public function isInfoDesk(): bool
    {
        foreach ($this->getGroups() as $group) {
            if ($group->getSlug() === 'info-desk') {
                return true;
            }
        }

        return false;
    }

    /** Special check if the user is a Director, shift manager or info desk */
    public function isShiftCoordinator(): bool
    {
        foreach ($this->getGroups() as $group) {
            switch ($group->getSlug()) {
                case 'department-manager':
                case 'info-desk':
                case 'shift-manager':
                case 'shift-manager-delegated':
                    return true;
            }
        }

        return false;
    }

    public function getUserIdentifier(): string
    {
        return $this->name;
    }
}
