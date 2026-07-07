<?php

namespace App\Entity;

use App\Repository\BannedIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A hashed record of an erased/banned identity, used to prevent the same person
 * from re-registering until the next event. The SSO user id and the email are
 * hashed separately (manual accounts store only the email hash); the raw values
 * are never stored. The ban list is cleared at each new event or after 90 days.
 */
#[ORM\Entity(repositoryClass: BannedIdentityRepository::class)]
#[ORM\Table(name: 'banned_identities')]
#[ORM\Index(name: 'idx_banned_hash', columns: ['hash'])]
#[ORM\HasLifecycleCallbacks]
class BannedIdentity
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_SSO = 'sso';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'hash_type', length: 16)]
    private string $hashType;

    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\Column(name: 'banned_at')]
    private \DateTimeImmutable $bannedAt;

    #[ORM\Column(name: 'appeal_requested_at', nullable: true)]
    private ?\DateTimeImmutable $appealRequestedAt = null;

    public function __construct(string $hashType, string $hash)
    {
        $this->hashType = $hashType;
        $this->hash = $hash;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->bannedAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHashType(): string
    {
        return $this->hashType;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getBannedAt(): \DateTimeImmutable
    {
        return $this->bannedAt;
    }

    public function getAppealRequestedAt(): ?\DateTimeImmutable
    {
        return $this->appealRequestedAt;
    }

    public function requestAppeal(): void
    {
        $this->appealRequestedAt ??= new \DateTimeImmutable();
    }

    public function hasAppeal(): bool
    {
        return $this->appealRequestedAt !== null;
    }
}
