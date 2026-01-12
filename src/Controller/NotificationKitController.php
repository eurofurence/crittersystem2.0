<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationKitController extends AbstractController
{
    #[Route('/dev/ui/notification-kit', name: 'app_notification_kit')]
    public function index(): Response
    {
       $this->addFlash('success', 'Operation completed successfully.');
        $this->addFlash('warning', 'This is a warning message.');
        $this->addFlash('danger', 'Something went wrong.');

        return $this->render('notification_kit/index.html.twig', [
            'pageTitle' => 'Notification Components (CS-004) Demo',
        ]);
    }
}
