<?php

namespace App\Controller;

use App\Security\AccessModeGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public notice shown to users the event-wide Access mode currently shuts out. It is where
 * {@see \App\EventSubscriber\AccessModeGateSubscriber} and the login flows send a non-qualifying
 * user, and it offers a link to the digital badge so they can still identify themselves to the
 * security team while the system is restricted.
 */
final class SystemStatusController extends AbstractController
{
    /** Public mode gates nobody, so the notice must not stay reachable once the event opens up. */
    #[Route('/unavailable', name: 'app_system_unavailable', methods: ['GET'])]
    public function unavailable(AccessModeGate $gate): Response
    {
        if (!$gate->isRestricted()) {
            return $this->redirectToRoute('app_news_index');
        }

        return $this->render('system/unavailable.html.twig', ['mode' => $gate->mode()]);
    }
}
