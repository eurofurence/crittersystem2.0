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
 * Forces users for whom 2FA is mandatory (global admins, and sub-admins an admin
 * has flagged) to enrol before using the application.
 */
final class TwoFactorEnforcementSubscriber implements EventSubscriberInterface
{
    private const ALLOWLIST = [
        '/2fa', '/logout', '/login', '/unsubscribe', '/erase', '/appeal',
        '/invite', '/admin/install', '/health', '/assets', '/_wdt', '/_profiler', '/_error',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isTwoFactorEnabled() || !$user->mustUseTwoFactor()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALLOWLIST as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_2fa_setup')));
    }
}
