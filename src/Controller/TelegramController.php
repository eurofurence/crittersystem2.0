<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\TelegramConfigurationRepository;
use App\Telegram\TelegramLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/** Self-service Telegram account linking from the user profile. */
#[Route('/profile/telegram')]
#[IsGranted('telegram:link')]
final class TelegramController extends AbstractController
{
    public function __construct(
        private readonly TelegramLinkService $links,
        private readonly TelegramConfigurationRepository $config,
    ) {
    }

    #[Route('', name: 'app_profile_telegram', methods: ['GET'])]
    public function index(): Response
    {
        $config = $this->config->current();

        return $this->render('telegram/index.html.twig', [
            'enabled' => $config?->isEnabled() ?? false,
            'pending' => $this->links->pendingFor($this->user()),
            'user' => $this->user(),
        ]);
    }

    #[Route('/start', name: 'app_profile_telegram_start', methods: ['POST'])]
    public function start(): Response
    {
        if (!($this->config->current()?->isEnabled() ?? false)) {
            $this->addFlash('danger', new TranslatableMessage('telegram.flash.not_enabled'));

            return $this->redirectToRoute('app_profile_telegram');
        }
        $this->links->startLink($this->user());

        return $this->redirectToRoute('app_profile_telegram');
    }

    #[Route('/status', name: 'app_profile_telegram_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(['linked' => $this->user()->isTelegramLinked()]);
    }

    #[Route('/unlink', name: 'app_profile_telegram_unlink', methods: ['POST'])]
    public function unlink(Request $request): Response
    {
        if ($this->isCsrfTokenValid('telegram_unlink', (string) $request->request->get('_token'))) {
            $this->links->unlink($this->user());
            $this->addFlash('success', new TranslatableMessage('telegram.flash.unlinked'));
        }

        return $this->redirectToRoute('app_profile_telegram');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
