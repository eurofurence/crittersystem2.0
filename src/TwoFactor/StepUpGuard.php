<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Guards step-up-protected actions. Returns a redirect when the current user
 * must complete (or set up) 2FA before continuing, or null when they may
 * proceed. Controllers call this at the top of sensitive actions.
 */
final class StepUpGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly StepUpManager $stepUp,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function guard(Request $request): ?RedirectResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        if ($user->isTwoFactorEnabled()) {
            return $this->stepUp->isFresh()
                ? null
                : new RedirectResponse($this->urlGenerator->generate('app_2fa_confirm', ['return' => $request->getRequestUri()]));
        }

        // No 2FA yet but it is mandatory for this user: force enrolment first.
        if ($user->mustUseTwoFactor()) {
            return new RedirectResponse($this->urlGenerator->generate('app_2fa_setup'));
        }

        return null;
    }
}
