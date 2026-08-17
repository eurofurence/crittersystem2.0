<?php

namespace App\Controller\Api\Bot;

use App\Api\Bot\BotShiftNormalizer;
use App\Entity\Certification;
use App\Entity\User;
use App\Repository\ShiftEntryRepository;
use App\Security\Bot\ActingUserAccess;
use App\Security\Bot\ActingUserResolver;
use App\Service\GoodieEligibilityService;
use App\Service\HoursCacheService;
use App\Service\NoShowBanService;
use App\Service\ProfileAccessService;
use App\Service\Notification\NotificationService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Volunteer profile and overview for the Telegram bot.
 */
#[Route('/api/bot/users')]
final class BotUserController extends AbstractController
{
    public function __construct(
        private readonly ActingUserResolver $actingUser,
        private readonly ActingUserAccess $access,
        private readonly ShiftEntryRepository $entries,
        private readonly HoursCacheService $hours,
        private readonly NoShowBanService $noShowBans,
        private readonly GoodieEligibilityService $goodies,
        private readonly NotificationService $notifications,
        private readonly BotShiftNormalizer $normalizer,
        private readonly ProfileAccessService $profileAccess,
    ) {
    }

    #[Route('/me', name: 'app_api_bot_user_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        return $this->json($this->user($this->actingUser->resolve($request)));
    }

    /**
     * Per-category Telegram consent for the acting volunteer.
     *
     * Consent is per category and defaults to OFF, so "the account is linked"
     * is not consent to anything: linking is how the volunteer reaches the bot,
     * not permission for it to message them. The bot must consult this before
     * sending anything it originates itself.
     *
     * Notifications sent through VMS (NotificationService::notify) already apply
     * these preferences server-side and need no check here.
     *
     * `telegram` is false for in-app-only categories whatever the volunteer set: those never leave
     * the web UI.
     */
    #[Route('/me/notification-preferences', name: 'app_api_bot_user_notification_preferences', methods: ['GET'])]
    public function notificationPreferences(Request $request): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);

        $categories = [];
        foreach ($this->notifications->preferenceMatrix($actor) as $row) {
            $categories[$row['category']] = [
                'label' => $row['label'],
                'telegram' => $row['telegram'],
            ];
        }

        return $this->json([
            'telegram_linked' => $actor->getTelegramId() !== null,
            'categories' => $categories,
        ]);
    }

    /**
     * Reading somebody else's overview is gated field by field, the same way the web profile is:
     * `profile:view` opens the page, but the ban figures need `user:delete` and the goodie ladder
     * needs `goodie:view`. Gating the whole response on `profile:view` alone would hand every shift
     * manager a volunteer's no-show tally and how close they are to an automatic ban, which the web
     * restricts to accounts that can ban in the first place.
     *
     * A volunteer's own overview is never narrowed.
     *
     * The no-show figure is the ban-relevant count, taken from the user's no-show baseline rather
     * than the all-time tally, because that is the number that decides the account's fate.
     */
    #[Route('/{id}/overview', name: 'app_api_bot_user_overview', methods: ['GET'])]
    public function overview(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $isSelf = $actor->getId() === $user->getId();
        if (!$isSelf) {
            $this->access->denyUnlessGranted($actor, 'profile:view');
        }

        $cache = $this->hours->get($user);

        $upcoming = 0;
        $now = new \DateTimeImmutable();
        foreach ($this->entries->findByUserOrdered($user) as $entry) {
            if ($entry->getShift()->getStartsAt() > $now) {
                ++$upcoming;
            }
        }

        $body = [
            'user_id' => (string) $user->getUuid(),
            'total_worked_hours' => $cache->getTotalHours(),
            'completed_shifts' => $cache->getCompletedShiftsCount(),
            'upcoming_shifts' => $upcoming,
            'night_shift_hours' => $cache->getNightShiftsHours(),
        ];

        if ($isSelf || $this->access->isGranted($actor, 'user:delete')) {
            $body['no_show_count'] = $this->noShowBans->noShowCount($user);
            $body['no_show_threshold'] = $this->noShowBans->threshold();
        }

        if ($isSelf || $this->access->isGranted($actor, 'goodie:view')) {
            $body['goodies'] = $this->goodieProgress($user);
        }

        return $this->json($body);
    }

    /**
     * Somebody else's shift history needs both halves of the web's rule
     * (ProfileAccessService::canViewHistory): `profile:history:view` says the holder may read shift
     * histories, and canView() says they may see this profile at all. Checking only the privilege
     * lets somebody who cannot open the profile read the history behind it. An unreadable profile
     * answers 404, so this surface does not confirm the account exists.
     */
    #[Route('/{id}/shifts', name: 'app_api_bot_user_shifts', methods: ['GET'])]
    public function shifts(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        if ($actor->getId() === $user->getId()) {
            $this->access->denyUnlessGranted($actor, 'shift:self');
        } else {
            if (!$this->profileAccess->canView($actor, $user)) {
                throw $this->createNotFoundException('Unknown user.');
            }
            $this->access->denyUnlessGranted($actor, 'profile:history:view');
        }

        $out = [];
        foreach ($this->entries->findByUserOrdered($user) as $entry) {
            $out[] = $this->normalizer->shift($entry->getShift(), $user)
                + ['entry' => $this->normalizer->entry($entry)];
        }

        return $this->json(['shifts' => $out]);
    }

    /**
     * Progress expressed against real goodie tiers. Critter 2.0 has no reward
     * "level" ladder; goodies gated on required hours are the only thing that
     * actually exists, so the bot reports those rather than invent a level.
     *
     * Every tier is matched explicitly. Treating "not pending" as earned would report a goodie the
     * volunteer is barred from as one they have already won, which is worse than not mentioning it.
     *
     * @return array<string, mixed>
     */
    private function goodieProgress(User $user): array
    {
        $evaluation = $this->goodies->evaluate($user);

        $earned = [];
        $blocked = [];
        $next = null;
        foreach ($evaluation['rows'] as $row) {
            $item = $row['item'];

            if ($row['tier'] === 'blocked') {
                $blocked[] = [
                    'id' => (string) $item->getUuid(),
                    'name' => $item->getName(),
                    'missing_certifications' => array_map(
                        static fn (Certification $c): string => $c->getTitle(),
                        $row['missingCertifications'],
                    ),
                ];

                continue;
            }

            if ($row['tier'] === 'pending') {
                if ($next === null || $row['gap'] < $next['hours_remaining']) {
                    $next = [
                        'id' => (string) $item->getUuid(),
                        'name' => $item->getName(),
                        'required_hours' => $item->getRequiredHours(),
                        'hours_remaining' => $row['gap'],
                    ];
                }

                continue;
            }

            $earned[] = [
                'id' => (string) $item->getUuid(),
                'name' => $item->getName(),
                'claimed' => $row['claimed'],
            ];
        }

        return ['eligible' => $earned, 'next' => $next, 'blocked' => $blocked];
    }

    /**
     * `display_name` is the username, never the real name: that is PII and lives in PersonalData.
     *
     * @return array<string, mixed>
     */
    private function user(User $user): array
    {
        $departments = [];
        foreach ($user->getActiveAssignments() as $assignment) {
            $department = $assignment->getDepartment();
            if ($department !== null) {
                $departments[(string) $department->getUuid()] = $department->getName();
            }
        }

        return [
            'id' => (string) $user->getUuid(),
            'display_name' => $user->getName(),
            'telegram_handle' => $user->getTelegramHandle(),
            'telegram_linked' => $user->getTelegramId() !== null,
            'role' => $user->getPositionBadge()?->getName(),
            'is_manager' => $user->hasAnyPrivilege(['shift:manage', 'assignment:manage', 'department:manage']),
            'managed_departments' => array_keys($departments),
            'managed_department_names' => array_values($departments),
        ];
    }
}
