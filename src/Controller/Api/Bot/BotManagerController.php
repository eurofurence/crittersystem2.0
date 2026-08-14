<?php

namespace App\Controller\Api\Bot;

use App\Api\Bot\BotShiftNormalizer;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Notification\NotificationCategories;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use App\Repository\UserRepository;
use App\Security\Bot\ActingUserAccess;
use App\Security\Bot\ActingUserResolver;
use App\Service\Notification\NotificationService;
use App\Service\NoShowBanService;
use App\Service\Shift\ShiftAttendanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shift-manager actions for the Telegram bot.
 *
 * Every scoped check here passes the shift as the subject. `assignment:manage` is
 * department-scoped, and PrivilegeVoter only enforces that scope when a subject is
 * supplied - checking it bare would let a manager scoped to one department act on
 * every shift in the event.
 */
#[Route('/api/bot/manager')]
final class BotManagerController extends AbstractController
{
    public function __construct(
        private readonly ActingUserResolver $actingUser,
        private readonly ActingUserAccess $access,
        private readonly ShiftRepository $shifts,
        private readonly ShiftEntryRepository $entries,
        private readonly UserRepository $users,
        private readonly NoShowBanService $noShowBans,
        private readonly NotificationService $notifications,
        private readonly BotShiftNormalizer $normalizer,
        private readonly ShiftAttendanceService $attendance,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/shifts', name: 'app_api_bot_manager_shifts', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'manageshifts:view');

        $shifts = $this->shifts->createQueryBuilder('s')
            ->andWhere('s.state = :published')
            ->setParameter('published', ShiftState::PUBLISHED)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()->getResult();

        $out = [];
        foreach ($shifts as $shift) {
            // Scoped per shift: the list must only contain what this manager may
            // actually act on, not every shift in the event.
            if ($this->access->isGranted($actor, 'shift:manage', $shift)) {
                $out[] = $this->normalizer->shift($shift);
            }
        }

        return $this->json(['shifts' => $out]);
    }

    #[Route('/shifts/{id}', name: 'app_api_bot_manager_shift', methods: ['GET'])]
    public function show(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'manageshifts:view');
        $this->access->denyUnlessGranted($actor, 'shift:manage', $shift);

        $assigned = [];
        $checkedIn = [];
        $missing = [];
        foreach ($shift->getEntries() as $entry) {
            $assigned[] = [
                'user_id' => (string) $entry->getUser()->getUuid(),
                'display_name' => $entry->getUser()->getName(),
                'volunteer_type' => $entry->getVolunteerType()->getName(),
                'checked_in_at' => $entry->getCheckedInAt()?->format(\DATE_ATOM),
                'checked_out_at' => $entry->getCheckedOutAt()?->format(\DATE_ATOM),
                'noshow' => $entry->isNoshow(),
            ];

            if ($entry->isCheckedIn()) {
                $checkedIn[] = (string) $entry->getUser()->getUuid();
            } elseif ($entry->isNoshow()) {
                $missing[] = (string) $entry->getUser()->getUuid();
            }
        }

        return $this->json([
            'shift' => $this->normalizer->shift($shift),
            'assigned' => $assigned,
            'checked_in_user_ids' => $checkedIn,
            'missing_user_ids' => $missing,
        ]);
    }

    #[Route('/shifts/{id}/checkin', name: 'app_api_bot_manager_checkin', methods: ['POST'])]
    public function checkIn(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $entry = $this->attendance->checkIn($this->authorizeEntry($request, $shift));

        return $this->json($this->normalizer->entry($entry));
    }

    #[Route('/shifts/{id}/checkout', name: 'app_api_bot_manager_checkout', methods: ['POST'])]
    public function checkOut(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $entry = $this->authorizeEntry($request, $shift);
        if (!$entry->isCheckedIn()) {
            return $this->json(['error' => 'not_checked_in'], Response::HTTP_CONFLICT);
        }

        return $this->json($this->normalizer->entry($this->attendance->checkOut($entry)));
    }

    #[Route('/shifts/{id}/noshow', name: 'app_api_bot_manager_noshow', methods: ['POST'])]
    public function noShow(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $entry = $this->authorizeEntry($request, $shift);
        $payload = $this->payload($request);

        $this->attendance->markNoShow($entry, ($payload['comment'] ?? null) ?: null);

        // Mirrors Manage\ShiftStaffingController::toggleNoshow(): reaching the
        // configured threshold locks the account. Skipping this would let no-shows
        // recorded through Telegram bypass a ban the same action triggers on the web.
        $banned = $this->noShowBans->evaluate($entry->getUser());

        return $this->json($this->normalizer->entry($entry) + ['user_banned' => $banned]);
    }

    #[Route('/shifts/{id}/message', name: 'app_api_bot_manager_message', methods: ['POST'])]
    public function message(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'message:use');
        $this->access->denyUnlessGranted($actor, 'shift:manage', $shift);

        $text = trim((string) ($this->payload($request)['text'] ?? ''));
        if ($text === '') {
            return $this->json(['error' => 'empty_message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // No VMS entity models a broadcast: Message is strictly 1:1. Notify each
        // assignee individually so per-user notification preferences still apply.
        $sent = 0;
        foreach ($shift->getEntries() as $entry) {
            $this->notifications->notify(
                $entry->getUser(),
                NotificationCategories::SHIFT_ASSIGNMENT,
                $shift->getTitle(),
                $text,
            );
            ++$sent;
        }

        return $this->json(['sent' => $sent]);
    }

    /**
     * Resolves the target entry and authorizes the actor for this specific shift.
     * The target user is named in the body; the actor comes from the header.
     */
    private function authorizeEntry(Request $request, Shift $shift): ShiftEntry
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'assignment:manage', $shift);

        $targetUuid = (string) ($this->payload($request)['target_user_id'] ?? '');
        if ($targetUuid === '') {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Missing target_user_id.');
        }

        $target = $this->users->findOneByUuid($targetUuid);
        if (!$target instanceof User) {
            throw $this->createNotFoundException('Unknown user.');
        }

        $entry = $this->entries->findOneByShiftAndUser($shift, $target);
        if ($entry === null) {
            throw $this->createNotFoundException('User is not assigned to this shift.');
        }

        return $entry;
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
