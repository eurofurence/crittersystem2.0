<?php

namespace App\Controller;

use App\Entity\User;
use App\Mercure\SubscriberCookieFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Keeps a page that is sitting still alive.
 *
 * The live transport is one long-lived connection to the hub, so it makes no requests to this
 * application at all. Two things still need a request:
 *
 *  - The idle timer. Every request refreshes it (see {@see \App\EventSubscriber\SessionIdleSubscriber}),
 *    which is what keeps an unattended bounty-board display signed in for the length of an event.
 *    Without a heartbeat that display is logged out overnight.
 *  - The subscriber token, which lives five minutes. A user reading one page for an hour must keep
 *    getting a fresh one, recomputed from their current permissions.
 *
 * Both are served by one small request every five minutes.
 */
#[IsGranted('ROLE_USER')]
final class HeartbeatController extends AbstractController
{
    public function __construct(private readonly SubscriberCookieFactory $cookies)
    {
    }

    /**
     * Never cached: the point of the request is its side effects, the refreshed idle timer and the
     * newly issued subscriber cookie.
     */
    #[Route('/session/heartbeat', name: 'app_session_heartbeat', methods: ['GET'])]
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $response = new JsonResponse(['ok' => true]);
        $response->headers->setCookie($this->cookies->create($user, $request->isSecure()));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
