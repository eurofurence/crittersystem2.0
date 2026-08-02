<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whether a user is currently held in the onboarding wizard.
 *
 * The single definition of that question. {@see \App\EventSubscriber\OnboardingGateSubscriber}
 * redirects on it, {@see \App\EventSubscriber\MercureSubscriber} decides whether to issue a
 * subscriber token on it, and the layout decides whether to render the live regions at all on it.
 *
 * Keeping it in one place is not tidiness. It was previously expressed in the templates as "has
 * completed onboarding", which is only half the rule: administrators are exempt from the gate, so a
 * site administrator who had never walked through the wizard - the single most likely account for
 * that - lost the navbar bell and the status widget, and was served no hub URL, which silently
 * stopped every live surface in the application including chat and the planner.
 */
final class OnboardingGate
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * True when this user would be sent to the wizard, and therefore refused the fragments the live
     * regions fetch.
     */
    public function blocks(?User $user): bool
    {
        if (!$user instanceof User || $user->isOnboardingCompleted()) {
            return false;
        }

        // The site administrator is never forced through onboarding.
        return !$this->security->isGranted('ROLE_ADMIN');
    }
}
