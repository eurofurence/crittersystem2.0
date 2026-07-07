<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\TwoFactor\StepUpManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reflects the current step-up freshness onto the security token as the
 * "mfa_verified" attribute, so audit entries record whether the action was
 * performed under a recent 2FA verification.
 */
final class StepUpTokenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly StepUpManager $stepUp,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 5]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $token = $this->security->getToken();
        $token?->setAttribute('mfa_verified', $this->stepUp->isFresh());
    }
}
