<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NavigationKitController extends AbstractController
{
    #[Route('/dev/ui/navigation-kit', name: 'app_navigation_kit')]
    public function index(): Response
    {
        return $this->render('navigation_kit/index.html.twig', [
            'pageTitle' => 'Navigation Components (CS-005) Demo',
        ]);
    }
}
