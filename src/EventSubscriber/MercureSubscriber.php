<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Mercure\SubscriberCookieFactory;
use App\Mercure\UpdatePublisher;
use App\Security\OnboardingGate;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches a fresh Mercure subscriber token to every page a signed-in user is served, and sends the
 * updates queued during the request once the response is on its way.
 *
 * The token is re-minted per page rather than once at sign-in, so a change in the user's permissions
 * takes effect on their next navigation rather than at their next login. The heartbeat covers users
 * who sit on one page (see {@see \App\Controller\HeartbeatController}).
 *
 * A user who has not finished onboarding gets no token: nothing is pushed to them, the wizard has no
 * live regions, and there is nothing for a stale token to reach.
 */
final class MercureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly OnboardingGate $gate,
        private readonly SubscriberCookieFactory $cookies,
        private readonly UpdatePublisher $publisher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onResponse',
            // After the response has been sent, and therefore after the transaction that produced
            // the change has committed.
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Only full HTML documents open a connection; a fragment, a redirect or a JSON reply is
        // served inside a page that already has a current token.
        if (!str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
            || $response->isRedirection()) {
            return;
        }

        $user = $this->security->getUser();
        // Not "has onboarded": administrators are exempt from the gate and must still get a token.
        if (!$user instanceof User || $this->gate->blocks($user)) {
            return;
        }

        /*
         * Minted on every page, deliberately, even though building the topic list costs a couple of
         * queries. Throttling it opens a correctness gap: a request can itself widen what the user
         * may receive - opening a support conversation makes the reader a participant of it - and a
         * token issued from a cached decision would omit the topic the very page being served needs.
         * Two queries per page view is the cheaper side of that trade.
         */
        $response->headers->setCookie($this->cookies->create($user, $request->isSecure()));
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->publisher->flush();
    }
}
