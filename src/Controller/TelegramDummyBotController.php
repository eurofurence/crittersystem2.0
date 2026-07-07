<?php

namespace App\Controller;

use App\Telegram\TelegramLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dummy bot endpoint used in development and tests to simulate the companion
 * Telegram bot confirming a link code. Disabled in production, where the real
 * bot server calls the integration API instead.
 */
final class TelegramDummyBotController extends AbstractController
{
    public function __construct(
        private readonly TelegramLinkService $links,
        private readonly string $environment,
    ) {
    }

    #[Route('/telegram/dummy/confirm', name: 'app_telegram_dummy_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        if ($this->environment === 'prod') {
            throw $this->createNotFoundException();
        }

        $user = $this->links->confirm(
            (string) $request->request->get('code'),
            (string) $request->request->get('telegram_id', '100200300'),
            $request->request->get('handle'),
        );

        return new JsonResponse(['ok' => $user !== null]);
    }
}
