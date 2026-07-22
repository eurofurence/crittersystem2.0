<?php

namespace App\Security\Bot;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The acting volunteer's account is no longer linked to Telegram.
 *
 * Unlinking in the web UI only nulls User::telegramId; the account still exists,
 * so without this the bot could keep acting for it forever off its own stale
 * local link record - reading shifts, the overview and the digital badge. The
 * machine-readable code lets the bot recognise a revoked link and drop it.
 */
final class ActingUserNotLinkedException extends AccessDeniedHttpException
{
    public const ERROR_CODE = 'acting_user_not_linked';

    public function __construct()
    {
        parent::__construct('Acting user is not linked to Telegram.');
    }
}
