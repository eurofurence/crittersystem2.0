<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends signed-in users who have not completed onboarding to the wizard, until
 * they finish. Site admins are exempt, as are the wizard itself, auth, the
 * unsubscribe link, the API, install and framework/asset routes.
 */
final class OnboardingGateSubscriber implements EventSubscriberInterface
{
    private const ALLOWLIST = [
        '/onboarding', '/logout', '/login', '/unsubscribe', '/admin/install',
        '/health', '/api', '/assets', '/_wdt', '/_profiler', '/_error',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall has established the token (priority 8).
        return [KernelEvents::REQUEST => ['onRequest', 6]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isOnboardingCompleted()) {
            return;
        }

        // The site administrator is never forced through onboarding.
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALLOWLIST as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_onboarding')));
    }
}
