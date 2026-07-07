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

    /** Add ban records for a user about to be erased. */
    public function ban(User $user): void
    {
        $this->add(BannedIdentity::TYPE_EMAIL, $this->hashEmail($user->getEmail()));
        if ($user->getSsoUserId() !== null) {
            $this->add(BannedIdentity::TYPE_SSO, $this->hashSso($user->getSsoUserId()));
        }
    }

    private function add(string $type, string $hash): void
    {
        if ($this->bans->findOneByHash($hash) === null) {
            $this->em->persist(new BannedIdentity($type, $hash));
        }
    }
}
