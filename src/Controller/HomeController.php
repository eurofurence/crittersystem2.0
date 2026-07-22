<?php

namespace App\Controller;

use App\Service\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * Site root: send authenticated users to their dashboard and guests to the
     * public landing/login page.
     */
    #[Route('/', name: 'app_root')]
    public function root(): Response
    {
        return $this->redirectToRoute($this->getUser() !== null ? 'app_dashboard' : 'app_login');
    }

    #[Route('/home', name: 'app_home')]
    public function index(AppVersion $version): Response
    {
        return $this->render('home/index.html.twig', [
            'version' => $version->get(),
        ]);
    }
}
