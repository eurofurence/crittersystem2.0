<?php

namespace App\Dev\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('global:admin')]
/**
 * The hub of the UI kit: it links to every other kit page and demonstrates the
 * navigation components themselves (nav_item, sidebar, sidebar_item).
 */
final class NavigationKitController extends AbstractController
{
    #[Route('/dev/ui/navigation-kit', name: 'app_navigation_kit')]
    public function index(): Response
    {
        return $this->render('navigation_kit/index.html.twig', [
            'pageTitle' => 'UI Kit - Navigation',
        ]);
    }
}
