<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the unauthenticated one-click unsubscribe link that every non-critical
 * email must carry, ensuring the recipient has a stable unsubscribe token.
 */
final class UnsubscribeLinker
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ensureToken(User $user): string
    {
        if ($user->getUnsubscribeToken() === null) {
            $user->setUnsubscribeToken(bin2hex(random_bytes(16)));
            $this->em->flush();
        }

        return $user->getUnsubscribeToken();
    }

    public function url(User $user, string $type): string
    {
        return $this->urlGenerator->generate(
            'app_unsubscribe',
            ['token' => $this->ensureToken($user), 'type' => $type],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
