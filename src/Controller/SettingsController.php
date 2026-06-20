<?php

namespace App\Controller;

use App\Entity\User;
use App\Theme\ThemeCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Personal settings hub: a small landing that links to the per-feature pages
 * (theme, API key, digital ID, …) and the theme picker itself.
 */
#[Route('/settings')]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThemeCatalog $themes,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig');
    }

    #[Route('/theme', name: 'app_settings_theme', methods: ['GET', 'POST'])]
    public function theme(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $settings = $user->getSettings();

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('settings-theme', (string) $request->request->get('_token'))) {
            $choice = (string) $request->request->get('theme', '');
            $newSlug = $choice === '' ? null : ($this->themes->find($choice)?->slug);
            if ($settings !== null) {
                $settings->setTheme($newSlug);
                $this->em->flush();
                $this->addFlash('success', $newSlug !== null
                    ? \sprintf('Theme set to "%s".', $this->themes->find($newSlug)->name)
                    : 'Theme reset to the system default.');
            }

            return $this->redirectToRoute('app_settings_theme');
        }

        return $this->render('settings/theme.html.twig', [
            'themes' => $this->themes->all(),
            'current' => $settings?->getTheme(),
        ]);
    }
}
