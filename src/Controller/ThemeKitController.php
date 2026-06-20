<?php

namespace App\Controller;

use App\Theme\ThemeCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Theme development/preview page (legacy `/design` parity). Shows a broad
 * Bootstrap component showcase that picks up the active theme — append
 * `?theme=<slug>` to switch without saving. Use this to author and check
 * new theme CSS files in `assets/themes/`.
 */
final class ThemeKitController extends AbstractController
{
    #[Route('/dev/kit/themes', name: 'app_theme_kit')]
    public function index(ThemeCatalog $catalog): Response
    {
        return $this->render('theme_kit/index.html.twig', [
            'themes' => $catalog->all(),
        ]);
    }
}
