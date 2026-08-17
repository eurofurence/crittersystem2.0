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
 * The rule is "would be sent to the wizard", never "has completed onboarding": administrators are
 * exempt from the gate, and a site administrator who has never walked through the wizard is the
 * most likely account for that. Asking the narrower question in a template costs such an account
 * the navbar bell, the status widget and its hub URL, which silently stops every live surface in
 * the application, chat and the planner included.
 */
final class OnboardingGate
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * True when this user would be sent to the wizard, and therefore refused the fragments the live
     * regions fetch. The site administrator is never forced through onboarding.
     */
    public function blocks(?User $user): bool
    {
        if (!$user instanceof User || $user->isOnboardingCompleted()) {
            return false;
        }

        return !$this->security->isGranted('ROLE_ADMIN');
    }
}
