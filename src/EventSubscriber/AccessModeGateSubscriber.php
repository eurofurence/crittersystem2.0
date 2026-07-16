<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\AccessModeGate;
use App\Security\LoginFormAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces the event-wide Access mode (see {@see AccessModeGate}) on every request.
 *
 * Because the mode is read from the database on each request, tightening it (e.g. to
 * "staff only") takes effect for every signed-in user on their very next request: a user who
 * no longer qualifies is signed out and their session invalidated — the mode change expires
 * their session without any need to reach into the session store.
 *
 * Deliberately NOT gated, so the system can still be entered and a badge still shown while it
 * is restricted:
 *  - authentication and the restricted-access notice itself (/login, /logout, /unavailable);
 *  - the whole /digital-id namespace — a volunteer must be able to display their digital badge
 *    for the security team even during a lockdown, and its public verify URL is scanned by them;
 *  - public, pre-authentication utility links (ban appeal, install, health, unsubscribe, erasure,
 *    invitation acceptance, the dev Telegram callback) and framework/asset routes;
 *  - the read-only API /info endpoint, which merely advertises the current mode.
 *
 * Anonymous requests are ignored here: the firewall's access_control already governs them, and
 * the intentionally-public surfaces above must stay reachable without a session.
 */
final class AccessModeGateSubscriber implements EventSubscriberInterface
{
    /** Web paths reachable regardless of the access mode. Matched as prefixes. */
    private const WEB_ALLOWLIST = [
        '/login', '/logout', '/unavailable', '/digital-id', '/appeal',
        '/admin/install', '/health', '/unsubscribe', '/erase', '/invite',
        '/telegram/dummy', '/assets', '/_wdt', '/_profiler', '/_error',
    ];

    /** The only API path reachable regardless of the access mode: it reports the mode. */
    private const API_ALLOWLIST = '/api/v0-beta/info';

    public function __construct(
        private readonly AccessModeGate $gate,
        private readonly Security $security,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall has established the token (priority 8), and ahead of the onboarding
        // gate (priority 6) so a user who fails the access gate is stopped before any other redirect.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $this->gate->permits($user)) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // The API firewall is stateless — deny with JSON, never touch a session.
        if (str_starts_with($path, '/api')) {
            if (!str_starts_with($path, self::API_ALLOWLIST)) {
                $event->setResponse(new JsonResponse(
                    ['message' => 'System access is currently restricted.'],
                    Response::HTTP_FORBIDDEN,
                ));
            }

            return;
        }

        foreach (self::WEB_ALLOWLIST as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $this->signOut($request);

        // A background request (poll, Turbo frame, fetch) must not be answered with an HTML page;
        // hand it the same session-expired signal the login entry point uses so the client reacts.
        if (LoginFormAuthenticator::isBackgroundRequest($request)) {
            $event->setResponse(new Response('', Response::HTTP_UNAUTHORIZED, [
                LoginFormAuthenticator::SESSION_EXPIRED_HEADER => '1',
            ]));

            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_system_unavailable')));
    }

    private function signOut(Request $request): void
    {
        $this->tokenStorage->setToken(null);
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->invalidate();
        }
    }
}
