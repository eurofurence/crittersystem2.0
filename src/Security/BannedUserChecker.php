<?php

namespace App\Security;

use App\Entity\User;
use App\Gdpr\BanChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses authentication for banned users. Applies to every
 * authenticator on the firewall, so a locked account cannot sign in to the web
 * application; the ban is keyed by the same hashed identity used elsewhere.
 */
final class BannedUserChecker implements UserCheckerInterface
{
    public function __construct(private readonly BanChecker $bans)
    {
    }

    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && $this->bans->isUserBanned($user)) {
            throw new CustomUserMessageAccountStatusException('Your account is locked. If you believe this is a mistake, you can appeal.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
