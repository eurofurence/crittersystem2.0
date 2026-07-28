<?php

namespace App\Entity;

use App\Entity\Concern\HasPublicUuid;
use App\Repository\LoginLockoutRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An active login timeout, either on one account or on one client address.
 *
 * While a lockout holds, password authentication for that subject fails even when the password is
 * correct, and the caller is told only that the credentials were wrong - a lockout that announced
 * itself would confirm the username exists and tell an attacker exactly when to resume.
 */
#[ORM\Entity(repositoryClass: LoginLockoutRepository::class)]
#[ORM\Table(name: 'login_lockouts')]
#[ORM\UniqueConstraint(name: 'uniq_login_lockout_subject', columns: ['scope', 'subject'])]
class LoginLockout
{
    use HasPublicUuid;

    /** The attempted username is locked, no matter where the attempts come from. */
    public const SCOPE_ACCOUNT = 'account';

    /** The client address is locked, no matter which username it tries. */
    public const SCOPE_IP = 'ip';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $scope;

    /** A lower-cased username for SCOPE_ACCOUNT, a hashed client address for SCOPE_IP. */
    #[ORM\Column(length: 180)]
    private string $subject;

    #[ORM\Column(name: 'locked_at')]
    private \DateTimeImmutable $lockedAt;

    #[ORM\Column(name: 'locked_until')]
    private \DateTimeImmutable $lockedUntil;

    /** Failures observed in the window that triggered this lockout. */
    #[ORM\Column(name: 'failure_count')]
    private int $failureCount;

    /** Distinct client addresses seen in that window; > 1 is what escalates an account lockout. */
    #[ORM\Column(name: 'source_count')]
    private int $sourceCount;

    public function __construct(
        string $scope,
        string $subject,
        \DateTimeImmutable $lockedAt,
        \DateTimeImmutable $lockedUntil,
        int $failureCount,
        int $sourceCount,
    ) {
        $this->uuid = Uuid::v4();
        $this->scope = $scope;
        $this->subject = $subject;
        $this->lockedAt = $lockedAt;
        $this->lockedUntil = $lockedUntil;
        $this->failureCount = $failureCount;
        $this->sourceCount = $sourceCount;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * An IP lockout's subject is a hash and means nothing to a reader, so it is never shown; the
     * management screen identifies those rows by their scope alone.
     */
    public function isAccountScope(): bool
    {
        return $this->scope === self::SCOPE_ACCOUNT;
    }

    public function getLockedAt(): \DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getLockedUntil(): \DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function getSourceCount(): int
    {
        return $this->sourceCount;
    }

    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return $this->lockedUntil > $now;
    }

    /** Renews an existing lockout when fresh failures arrive while it is still running. */
    public function extend(\DateTimeImmutable $lockedUntil, int $failureCount, int $sourceCount): void
    {
        $this->lockedUntil = $lockedUntil;
        $this->failureCount = $failureCount;
        $this->sourceCount = $sourceCount;
    }
}
