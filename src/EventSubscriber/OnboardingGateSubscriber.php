<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\OnboardingGate;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly OnboardingGate $gate,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** Priority 6 runs after the firewall (8) has established the token. */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 6]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$this->gate->blocks($user instanceof User ? $user : null)) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        foreach (self::ALLOWLIST as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        if ($request->isXmlHttpRequest()) {
            $event->setResponse(new Response('', Response::HTTP_FORBIDDEN));

            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_onboarding')));
    }
}
