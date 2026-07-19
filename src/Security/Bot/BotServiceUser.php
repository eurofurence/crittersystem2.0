<?php

namespace App\Security\Bot;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The Telegram bot itself, as authenticated by the shared service token.
 *
 * This identity deliberately carries no roles and no privileges: the token only
 * proves the request came from the bot, never that the action is allowed. Every
 * authorization decision on /api/bot is made against the *acting* volunteer
 * instead - see {@see ActingUserAccess}.
 */
final class BotServiceUser implements UserInterface
{
    public function getRoles(): array
    {
        return [];
    }

    public function getUserIdentifier(): string
    {
        return 'telegram-bot';
    }

    public function eraseCredentials(): void
    {
    }
}
