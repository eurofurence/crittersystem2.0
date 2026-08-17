<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Security\Exception\AccountLockedOutException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Writes authentication outcomes to the audit log: successful logins, failed
 * login attempts (with the attempted identifier) and logouts.
 */
final class AuthenticationAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->audit->log(AuditEvents::AUTHENTICATION, AuditEvents::LOGIN, [
            'actorUser' => $user,
            'details' => ['firewall' => $event->getFirewallName()],
        ]);
    }

    /**
     * A refusal by the brute-force throttle is not recorded here: it is already recorded once, as
     * LOGIN_LOCKED, and the lockout row carries the failure count.
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ($event->getException() instanceof AccountLockedOutException) {
            return;
        }

        $passport = $event->getPassport();
        $username = $passport?->hasBadge(UserBadge::class)
            ? $passport->getBadge(UserBadge::class)->getUserIdentifier()
            : null;

        $this->audit->log(AuditEvents::AUTHENTICATION, AuditEvents::LOGIN_FAILED, [
            'outcome' => AuditEvents::FAILURE,
            'actorUsername' => $username,
            'errorMessage' => $event->getException()->getMessageKey(),
        ]);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->audit->log(AuditEvents::AUTHENTICATION, AuditEvents::LOGOUT, [
            'actorUser' => $user,
        ]);
    }
}
