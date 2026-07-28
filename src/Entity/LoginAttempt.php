<?php

namespace App\Entity;

use App\Repository\LoginAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One failed username+password login, kept only long enough to decide whether a
 * brute force is under way (see {@see \App\Security\LoginThrottle}).
 *
 * The client address is stored as a salted hash, never in the clear: the throttle only ever tests
 * two addresses for equality, so keeping the raw IP would retain personal data we have no use for.
 * Successful logins are not recorded here; the audit log covers those.
 */
#[ORM\Entity(repositoryClass: LoginAttemptRepository::class)]
#[ORM\Table(name: 'login_attempts')]
#[ORM\Index(name: 'idx_login_attempt_username', columns: ['username_key', 'attempted_at'])]
#[ORM\Index(name: 'idx_login_attempt_ip', columns: ['ip_hash', 'attempted_at'])]
class LoginAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The attempted identifier, lower-cased so casing variants count as the same target. */
    #[ORM\Column(name: 'username_key', length: 180)]
    private string $usernameKey;

    #[ORM\Column(name: 'ip_hash', length: 64)]
    private string $ipHash;

    #[ORM\Column(name: 'attempted_at')]
    private \DateTimeImmutable $attemptedAt;

    public function __construct(string $usernameKey, string $ipHash, \DateTimeImmutable $attemptedAt)
    {
        $this->usernameKey = $usernameKey;
        $this->ipHash = $ipHash;
        $this->attemptedAt = $attemptedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsernameKey(): string
    {
        return $this->usernameKey;
    }

    public function getIpHash(): string
    {
        return $this->ipHash;
    }

    public function getAttemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }
}
