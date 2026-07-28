<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Exception\AccountLockedOutException;
use App\Security\LoginThrottle;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Feeds the brute-force counters from the outcome of each username+password login.
 *
 * Only the interactive `main` firewall is counted. The API-key and bot firewalls authenticate
 * machine callers with tokens, not passwords, and running them through an account timeout would let
 * a broken integration lock a volunteer out of the web UI.
 */
final class LoginThrottleSubscriber implements EventSubscriberInterface
{
    private const THROTTLED_FIREWALL = 'main';

    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly RequestStack $requests,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getFirewallName() !== self::THROTTLED_FIREWALL) {
            return;
        }

        // Our own refusal of an already-locked attempt. Counting it would let the attacker's
        // continued guessing renew the timeout indefinitely, so it never expires.
        if ($event->getException() instanceof AccountLockedOutException) {
            return;
        }

        // A rejected CSRF token is a login page that went stale in an open tab, not a guess at a
        // password - the request never reached the credential check. Counting it would let someone
        // who left a tab open all afternoon lock themselves out on their third try, and it buys
        // nothing: an attacker cannot test a password without a token that validates.
        if ($event->getException() instanceof InvalidCsrfTokenException) {
            return;
        }

        // No passport at all when authenticate() itself refused the request.
        if (!$event->getPassport()?->hasBadge(UserBadge::class)) {
            return;
        }

        $this->throttle->recordFailure(
            $event->getPassport()->getBadge(UserBadge::class)->getUserIdentifier(),
            $event->getRequest()->getClientIp(),
        );
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== self::THROTTLED_FIREWALL) {
            return;
        }

        // Clears the identifier that was actually submitted, not the resolved username: the account
        // may have been reached by email, and that is the string the counter was keyed on.
        $submitted = (string) $this->requests->getCurrentRequest()?->getPayload()->get('_username');
        $this->throttle->clearFailures($submitted !== '' ? $submitted : $event->getUser()->getUserIdentifier());
    }
}
