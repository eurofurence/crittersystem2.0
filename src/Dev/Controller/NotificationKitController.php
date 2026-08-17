<?php

namespace App\Dev\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('global:admin')]
final class NotificationKitController extends AbstractController
{
    /**
     * The demo flashes are not rendered by this page's template: base.html.twig draws them with
     * n.flash_messages(app), above the page body.
     */
    #[Route('/dev/ui/notification-kit', name: 'app_notification_kit')]
    public function index(): Response
    {
        $this->addFlash('success', 'Demo Department was saved.');
        $this->addFlash('warning', 'example@demo.invalid has not confirmed their address yet.');
        $this->addFlash('danger', 'Could not reach the demo server.');

        return $this->render('notification_kit/index.html.twig', [
            'pageTitle' => 'UI Kit - Notifications',
        ]);
    }
}
