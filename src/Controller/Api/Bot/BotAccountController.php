<?php

namespace App\Controller\Api\Bot;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Bot\ActingUserAccess;
use App\Security\Bot\ActingUserResolver;
use App\Service\DigitalIdService;
use App\Telegram\TelegramLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Account linking and the digital badge for the Telegram bot.
 */
#[Route('/api/bot')]
final class BotAccountController extends AbstractController
{
    public function __construct(
        private readonly ActingUserResolver $actingUser,
        private readonly ActingUserAccess $access,
        private readonly UserRepository $users,
        private readonly TelegramLinkService $linking,
        private readonly DigitalIdService $digitalId,
    ) {
    }

    /**
     * Confirms a one-time code the volunteer generated in the web UI.
     *
     * Deliberately the only bot endpoint with no acting user: this is what
     * establishes the link in the first place, so the caller cannot yet name a
     * volunteer. The code is the proof of identity.
     */
    #[Route('/users/link-telegram', name: 'app_api_bot_link', methods: ['POST'])]
    public function link(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $code = trim((string) ($payload['code'] ?? ''));
        $telegramId = trim((string) ($payload['telegram_user_id'] ?? ''));

        if ($code === '' || $telegramId === '') {
            return $this->json(['error' => 'code_and_telegram_user_id_required'], Response::HTTP_BAD_REQUEST);
        }

        $handle = $payload['telegram_handle'] ?? null;
        $user = $this->linking->confirm($code, $telegramId, $handle !== null ? (string) $handle : null);

        // The service returns null for unknown, expired, already-used codes and for
        // banned accounts alike. Keep that indistinguishable: a bot caller must not
        // be able to probe which codes exist or which accounts are locked.
        if ($user === null) {
            return $this->json(['error' => 'invalid_code'], Response::HTTP_FORBIDDEN);
        }

        // The acting token leaves the server exactly once, here, to the bot that
        // just proved the link with a valid code. It is the bot's credential for
        // every subsequent call as this volunteer; it is never returned again.
        return $this->json([
            'id' => (string) $user->getUuid(),
            'display_name' => $user->getName(),
            'telegram_handle' => $user->getTelegramHandle(),
            'acting_token' => $user->getTelegramActingToken(),
        ]);
    }

    #[Route('/users/unlink-telegram', name: 'app_api_bot_unlink', methods: ['POST'])]
    public function unlink(Request $request): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);

        $targetUuid = trim((string) ($this->payload($request)['user_id'] ?? ''));
        $target = $actor;
        if ($targetUuid !== '' && $targetUuid !== (string) $actor->getUuid()) {
            $found = $this->users->findOneByUuid($targetUuid);
            if (!$found instanceof User) {
                throw $this->createNotFoundException('Unknown user.');
            }
            $this->access->denyUnlessGranted($actor, 'user:telegram:admin');
            $target = $found;
        }

        $this->linking->unlink($target, $actor);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Issues the short-lived opaque token the bot renders as a QR code. The token
     * carries no personal data and is validated by VMS at /digital-id/verify.
     */
    #[Route('/digital-id/token', name: 'app_api_bot_digital_id_token', methods: ['POST'])]
    public function digitalIdToken(Request $request): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $token = $this->digitalId->getOrCreateActive($actor);

        return $this->json([
            'token' => $token->getToken(),
            'expires_at' => $token->getExpiresAt()->format(\DATE_ATOM),
            'display_name' => $actor->getName(),
            'badge_number' => $actor->getPersonalData()?->getBadgeNumber(),
            'role' => $actor->getPositionBadge()?->getName(),
        ]);
    }

    #[Route('/digital-id/status', name: 'app_api_bot_digital_id_status', methods: ['GET'])]
    public function digitalIdStatus(Request $request): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $token = $this->digitalId->getOrCreateActive($actor);

        return $this->json([
            'active' => !$token->isExpired(),
            'expires_at' => $token->getExpiresAt()->format(\DATE_ATOM),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $content = (string) $request->getContent();
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
