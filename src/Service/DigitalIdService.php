<?php

namespace App\Service;

use App\Entity\DigitalIdToken;
use App\Entity\User;
use App\Repository\DigitalIdTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues and rotates short-lived digital-ID tokens that back the user's
 * public-scannable QR code.
 */
final class DigitalIdService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DigitalIdTokenRepository $tokens,
    ) {
    }

    /**
     * A token with less than this left is rotated rather than reused, so a QR we
     * are about to render is always scannable for a usable stretch of time.
     */
    public const MIN_REMAINING_SECONDS = 30;

    /**
     * Return the user's active token, creating a fresh one when it is missing,
     * expired, or too close to expiry to be worth showing.
     */
    public function getOrCreateActive(User $user): DigitalIdToken
    {
        $token = $this->tokens->findActiveForUser($user);
        if ($token !== null && $token->getExpiresAt()->getTimestamp() - time() > self::MIN_REMAINING_SECONDS) {
            return $token;
        }

        return $this->refresh($user);
    }

    public function refresh(User $user): DigitalIdToken
    {
        $token = new DigitalIdToken($user);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    public function findActive(string $token): ?DigitalIdToken
    {
        return $this->tokens->findActive($token);
    }
}
