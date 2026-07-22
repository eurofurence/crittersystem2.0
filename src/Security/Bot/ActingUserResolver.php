<?php

namespace App\Security\Bot;

use App\Entity\User;
use App\Gdpr\BanChecker;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Resolves the volunteer a /api/bot request acts on behalf of, from the
 * "X-Acting-User: <user uuid>" header.
 *
 * The actor is deliberately separate from any id in the path or body: those name
 * the *target* of an action, and acting on another user's record is a privileged
 * operation the caller must be authorized for (see {@see ActingUserAccess}).
 *
 * Banned users are refused here, mirroring BannedUserChecker on the web firewall -
 * the bot bypasses that checker, so without this a locked account could keep
 * working shifts through Telegram.
 */
final class ActingUserResolver
{
    public const HEADER = 'X-Acting-User';
    public const TOKEN_HEADER = 'X-Acting-Token';

    public function __construct(
        private readonly UserRepository $users,
        private readonly BanChecker $bans,
    ) {
    }

    public function resolve(Request $request): User
    {
        $uuid = (string) $request->headers->get(self::HEADER, '');
        if ($uuid === '') {
            throw new BadRequestHttpException(sprintf('Missing %s header.', self::HEADER));
        }

        $user = $this->users->findOneByUuid($uuid);
        if ($user === null) {
            throw new BadRequestHttpException('Unknown acting user.');
        }

        if ($this->bans->isUserBanned($user)) {
            throw new AccessDeniedHttpException('Acting user is banned.');
        }

        // The account may still exist after the volunteer unlinks in the web UI
        // (unlink only nulls telegramId). Refuse it here so the bot cannot keep
        // acting on a revoked link - this is the single choke point every
        // acting-on-behalf endpoint passes through.
        if (!$user->isTelegramLinked()) {
            throw new ActingUserNotLinkedException();
        }

        // The uuid names who to act as, but is public and permanent. The acting
        // token is the revocable proof that the link is *current*: it is rotated
        // on every link and nulled on unlink, so a token from a since-revoked
        // link (or from a different Telegram account linked before) no longer
        // matches and is refused - even though the uuid is linked again.
        $expected = $user->getTelegramActingToken();
        $presented = (string) $request->headers->get(self::TOKEN_HEADER, '');
        if ($expected === null || $presented === '' || !hash_equals($expected, $presented)) {
            throw new ActingUserNotLinkedException();
        }

        return $user;
    }
}
