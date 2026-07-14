<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Entity\BannedIdentity;
use App\Entity\User;
use App\Repository\BannedIdentityRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maintains and checks the hashed ban list used to keep an erased person from
 * re-registering until the next event. Identities are stored only as one-way
 * HMAC-SHA256 hashes (email always; SSO id additionally when present).
 */
final class BanChecker
{
    public function __construct(
        private readonly BannedIdentityRepository $bans,
        private readonly EntityManagerInterface $em,
        #[\SensitiveParameter]
        private readonly string $banPepper,
    ) {
    }

    public function hashEmail(string $email): string
    {
        return hash_hmac('sha256', 'email:'.mb_strtolower(trim($email)), $this->banPepper);
    }

    public function hashSso(string $ssoId): string
    {
        return hash_hmac('sha256', 'sso:'.$ssoId, $this->banPepper);
    }

    public function isEmailBanned(string $email): bool
    {
        return $this->bans->findOneByHash($this->hashEmail($email)) !== null;
    }

    public function isBanned(?string $email, ?string $ssoId): bool
    {
        if ($email !== null && $email !== '' && $this->isEmailBanned($email)) {
            return true;
        }

        return $ssoId !== null && $ssoId !== '' && $this->bans->findOneByHash($this->hashSso($ssoId)) !== null;
    }

    /** Add ban records for a user about to be erased (GDPR). */
    public function ban(User $user): void
    {
        $this->add(BannedIdentity::TYPE_EMAIL, $this->hashEmail($user->getEmail()), $user, 'Account erased (GDPR).', false, null);
        if ($user->getSsoUserId() !== null) {
            $this->add(BannedIdentity::TYPE_SSO, $this->hashSso($user->getSsoUserId()), $user, 'Account erased (GDPR).', false, null);
        }
    }

    /**
     * Create a behavioural ban linked to a live user. The identity is
     * still hashed so login/SSO/registration checks work unchanged; the user
     * link and reason are stored for admin review.
     */
    public function banUser(User $user, string $reason, bool $isAutomatic, ?int $noShowCount = null): void
    {
        $this->add(BannedIdentity::TYPE_EMAIL, $this->hashEmail($user->getEmail()), $user, $reason, $isAutomatic, $noShowCount);
        if ($user->getSsoUserId() !== null) {
            $this->add(BannedIdentity::TYPE_SSO, $this->hashSso($user->getSsoUserId()), $user, $reason, $isAutomatic, $noShowCount);
        }
    }

    public function isUserBanned(User $user): bool
    {
        return $this->isBanned($user->getEmail(), $user->getSsoUserId());
    }

    /** Remove every ban record for the user (by hash or user link). Returns the number removed. */
    public function liftUser(User $user): int
    {
        /** @var array<int, BannedIdentity> $rows */
        $rows = [];
        foreach ([$this->hashEmail($user->getEmail()), $user->getSsoUserId() !== null ? $this->hashSso($user->getSsoUserId()) : null] as $hash) {
            if ($hash !== null && ($ban = $this->bans->findOneByHash($hash)) !== null) {
                $rows[(int) $ban->getId()] = $ban;
            }
        }
        foreach ($this->bans->findByUser($user) as $ban) {
            $rows[(int) $ban->getId()] = $ban;
        }

        foreach ($rows as $ban) {
            $this->em->remove($ban);
        }

        return \count($rows);
    }

    private function add(string $type, string $hash, ?User $user, ?string $reason, bool $isAutomatic, ?int $noShowCount): void
    {
        if ($this->bans->findOneByHash($hash) !== null) {
            return;
        }

        $ban = (new BannedIdentity($type, $hash))
            ->setUser($user)
            ->setReason($reason)
            ->setAutomatic($isAutomatic)
            ->setNoShowCount($noShowCount);
        $this->em->persist($ban);
    }
}
