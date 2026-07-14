<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\EventConfigStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Expires a session that has gone quiet, and refreshes one that has not.
 *
 * This is enforced here rather than through `framework.session.*` for two reasons: that configuration is
 * compiled into the container and so cannot read an admin-editable value out of the database, and PHP's
 * own garbage collection is probabilistic, which makes the moment of expiry unpredictable. Checking the
 * stamp on every request is deterministic and reconfigurable at runtime.
 *
 * It must run before the firewall, so that invalidating the session leaves an ordinary anonymous request
 * behind and the normal entry point decides what the caller sees (a redirect for a navigation, a 401 for
 * a background request — see App\Security\LoginFormAuthenticator).
 *
 * Every request refreshes the stamp, including the widgets that poll in the background. That is
 * deliberate: it keeps the bounty board signed in on a display that nobody touches for days. The cost is
 * that any *visible* tab stays signed in indefinitely, because polling only stops when the tab is hidden
 * — so the idle limit really governs tabs that are hidden or closed, not unattended ones.
 */
final class SessionIdleSubscriber implements EventSubscriberInterface
{
    private const KEY = '_last_activity';

    /** Above the firewall (8), so an expired session is already gone when authentication runs. */
    private const PRIORITY = 9;

    public function __construct(private readonly EventConfigStore $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', self::PRIORITY]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        /*
         * Only ever touch a session that already exists. The session is lazy and this runs before the
         * firewall has read it, so `isStarted()` is still false here — the cookie is the only honest
         * signal. Reading the session unconditionally would start one, and hand a cookie and a session
         * file to every anonymous visitor and every crawler.
         */
        if (!$session->isStarted() && !$request->cookies->has($session->getName())) {
            return;
        }

        $last = $session->get(self::KEY);
        if (\is_int($last) && (time() - $last) > $this->idleSeconds()) {
            $session->invalidate();

            return;
        }

        $session->set(self::KEY, time());
    }

    private function idleSeconds(): int
    {
        $minutes = $this->config->getInt(
            EventConfigStore::KEY_SESSION_IDLE_MINUTES,
            EventConfigStore::DEFAULT_SESSION_IDLE_MINUTES,
        );

        // A zero or negative limit would log everyone out on their next request.
        return max(1, $minutes) * 60;
    }
}
