<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Applies an administrator's queued onboarding reset when the user signs in.
 *
 * The reset is deferred rather than applied when the administrator asks for it:
 * OnboardingGateSubscriber reads the completed flag on every request, and the
 * user provider reloads the user from the database each time, so clearing the
 * flag directly would redirect anyone already signed in into the wizard on their
 * next click. Queueing it leaves live sessions untouched and forces onboarding
 * at the next sign-in, which is the point of the feature.
 *
 * Only the interactive firewall counts. LoginSuccessEvent also fires for the
 * stateless API-key and bot-token firewalls, and a background API call must not
 * silently consume the pending reset - the user would never see the wizard.
 */
final class OnboardingResetSubscriber implements EventSubscriberInterface
{
    public const FIREWALL = 'main';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    /** Applying the reset consumes it: resetOnboarding() also clears the pending request. */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ($event->getFirewallName() !== self::FIREWALL) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User || !$user->isOnboardingResetPending()) {
            return;
        }

        $requestedAt = $user->getOnboardingResetRequestedAt();
        $user->resetOnboarding();
        $this->em->flush();

        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
            'actorUser' => $user,
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => [
                'onboarding' => 'reset_applied_at_login',
                'requested_at' => $requestedAt?->format(\DATE_ATOM),
            ],
        ]);
    }
}
