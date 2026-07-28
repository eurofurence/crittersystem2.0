<?php

namespace App\Security\Exception;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Raised when a login is refused because the account or the client address is inside a brute-force
 * timeout, whether or not the submitted password was correct.
 *
 * It extends BadCredentialsException so that {@see getMessageKey()} is inherited unchanged: the
 * login page must render exactly the same "invalid credentials" message it shows for a wrong
 * password. Anything more specific would confirm that the username exists and tell an attacker
 * precisely when their guessing was throttled - and when it is worth resuming.
 *
 * The distinct class exists only so the throttle can recognise its own refusals and not count them
 * as fresh failures; see App\EventSubscriber\LoginThrottleSubscriber. Without that, every blocked
 * attempt would extend the timeout and the lockout would never end.
 */
final class AccountLockedOutException extends BadCredentialsException
{
}
