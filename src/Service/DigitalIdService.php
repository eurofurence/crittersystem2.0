<?php

namespace App\Service;

use App\Entity\DigitalIdToken;
use App\Entity\User;
use App\Repository\DigitalIdTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues and rotates short-lived digital-ID tokens that back the user's
 * public-scannable QR code (§ legacy DigitalIdController/QrController).
 */
final class DigitalIdService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DigitalIdTokenRepository $tokens,
    ) {
    }

    /** Return the user's active token, creating a fresh one when missing/expired. */
    public function getOrCreateActive(User $user): DigitalIdToken
    {
        return $this->tokens->findActiveForUser($user) ?? $this->refresh($user);
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
