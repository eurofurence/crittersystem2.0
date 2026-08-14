<?php

namespace App\Controller\Api\Bot;

use App\Api\Bot\BotShiftNormalizer;
use App\Entity\Shift;
use App\Entity\VolunteerType;
use App\Exception\CapacityConflictException;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use App\Repository\VolunteerTypeRepository;
use App\Security\Bot\ActingUserAccess;
use App\Security\Bot\ActingUserResolver;
use App\Service\Shift\ShiftGroupSignupService;
use App\Service\Shift\ShiftVisibilityResolver;
use App\Service\ShiftSignupService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shift browsing and sign-up for the Telegram bot.
 *
 * Authorization is never done with #[IsGranted] here: the firewall token
 * identifies the bot, so it would check the wrong identity. Everything goes
 * through ActingUserAccess against the acting volunteer.
 */
#[Route('/api/bot')]
final class BotShiftController extends AbstractController
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly ActingUserResolver $actingUser,
        private readonly ActingUserAccess $access,
        private readonly ShiftRepository $shifts,
        private readonly ShiftEntryRepository $entries,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly ShiftSignupService $signup,
        private readonly ShiftGroupSignupService $groupSignup,
        private readonly ShiftVisibilityResolver $visibility,
        private readonly BotShiftNormalizer $normalizer,
        private readonly \App\Service\Shift\ShiftEligibility $eligibility,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/shifts', name: 'app_api_bot_shifts', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'shift:view');

        $qb = $this->shifts->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'ASC');

        $this->visibility->applyVisibilityFor($qb, $actor, $this->visibility->memberDepartmentIds($actor));

        if ($request->query->get('date') !== null) {
            $day = $this->parseDate((string) $request->query->get('date'));
            $qb->andWhere('s.startsAt >= :from')->andWhere('s.startsAt < :to')
                ->setParameter('from', $day->setTime(0, 0))
                ->setParameter('to', $day->setTime(0, 0)->modify('+1 day'));
        } else {
            $qb->andWhere('s.endsAt >= :now')->setParameter('now', new \DateTimeImmutable());
        }

        foreach (['department_id' => 'department', 'location_id' => 'location', 'shift_task_id' => 'shiftTask'] as $param => $association) {
            $uuid = $request->query->get($param);
            if ($uuid === null || $uuid === '') {
                continue;
            }
            if (!Uuid::isValid((string) $uuid)) {
                throw new BadRequestHttpException(sprintf('Malformed %s.', $param));
            }

            $qb->join('s.'.$association, $association)
                ->andWhere($association.'.uuid = :'.$association)
                ->setParameter($association, (string) $uuid);
        }

        $limit = min(max($request->query->getInt('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $qb->setMaxResults($limit);

        $shifts = $qb->getQuery()->getResult();

        $openOnly = $request->query->getBoolean('open_only');
        $suitable = $request->query->getBoolean('suitable_for_me');

        $visible = $this->visibility->filterVisible($shifts, $actor);

        $this->eligibility->warmUp($actor, $visible);
        try {
            $out = [];
            foreach ($visible as $shift) {
                $row = $this->normalizer->shift($shift, $actor);
                if ($openOnly && $row['open_slots'] < 1) {
                    continue;
                }
                if ($suitable && $row['my_state'] !== 'available') {
                    continue;
                }
                $out[] = $row;
            }
        } finally {
            $this->eligibility->coolDown();
        }

        return $this->json(['shifts' => $out]);
    }

    #[Route('/shifts/{id}', name: 'app_api_bot_shift', methods: ['GET'])]
    public function show(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'shift:view');
        $this->requireVisible($shift, $actor);

        return $this->json($this->normalizer->shift($shift, $actor));
    }

    #[Route('/shifts/{id}/apply', name: 'app_api_bot_shift_apply', methods: ['POST'])]
    public function apply(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'shift:apply');
        $this->requireVisible($shift, $actor);

        $payload = $this->payload($request);

        $requested = $payload['volunteer_type_id'] ?? null;
        $type = $this->resolveVolunteerType($requested, $shift, $actor);

        if ($type === null && $requested !== null) {
            return $this->json(['error' => 'unknown_volunteer_type'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($type === null) {
            $options = $this->signup->signupOptions($shift, $actor);

            // Several types on offer: the volunteer genuinely has to pick one.
            if (\count($options) > 1) {
                return $this->json([
                    'error' => 'volunteer_type_required',
                    'message' => 'Provide volunteer_type_id; this shift needs more than one type.',
                    'options' => array_map(
                        fn (VolunteerType $t): array => ['id' => (string) $t->getUuid(), 'name' => $t->getName()],
                        array_values($options),
                    ),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json([
                'error' => 'signup_refused',
                'message' => $this->refusalReason($actor, $shift),
            ], Response::HTTP_CONFLICT);
        }

        $error = $this->signup->signUpError($actor, $shift, $type);
        if ($error !== null) {
            return $this->json(['error' => 'signup_refused', 'message' => $error], Response::HTTP_CONFLICT);
        }

        $plan = $this->signup->plan($actor, $shift, $type);
        if ($plan->isGrouped() && !($payload['confirm_group'] ?? false)) {
            return $this->json([
                'error' => 'group_confirmation_required',
                'message' => 'These shifts are taken together. Show the volunteer every shift below, then repeat the request with confirm_group: true.',
                'group' => $this->normalizer->shift($shift, $actor)['group'],
            ], Response::HTTP_CONFLICT);
        }

        try {
            $entries = $this->groupSignup->signUpGroup(
                $actor,
                $shift,
                $type,
                [],
                $payload['comment'] ?? null,
                (bool) ($payload['acknowledge_hours'] ?? false),
            );
        } catch (CapacityConflictException $e) {
            return $this->json(['error' => 'capacity_conflict', 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => 'signup_refused', 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        $requested = null;
        foreach ($entries as $created) {
            if ($created->getShift() === $shift) {
                $requested = $created;
            }
        }

        $body = $this->normalizer->entry($requested ?? $entries[0]);
        if (\count($entries) > 1) {
            $body['group_entries'] = array_map(fn ($entry): array => $this->normalizer->entry($entry), $entries);
        }

        return $this->json($body, Response::HTTP_CREATED);
    }

    #[Route('/shifts/{id}/cancel', name: 'app_api_bot_shift_cancel', methods: ['POST'])]
    public function cancel(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): JsonResponse
    {
        $actor = $this->actingUser->resolve($request);
        $this->access->denyUnlessGranted($actor, 'shift:apply');

        $entry = $this->entries->findOneByShiftAndUser($shift, $actor);
        if ($entry === null) {
            return $this->json(['error' => 'not_signed_up'], Response::HTTP_NOT_FOUND);
        }

        $error = $this->signup->cancelError($entry, false);
        if ($error !== null) {
            return $this->json(['error' => 'cancel_refused', 'message' => $error], Response::HTTP_CONFLICT);
        }

        $removed = $this->groupSignup->cancelGroup($entry);

        if (\count($removed) <= 1) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return $this->json([
            'cancelled' => array_map(static fn ($held): string => (string) $held->getShift()->getUuid(), $removed),
        ]);
    }

    /**
     * A malformed value is a caller bug worth surfacing on this surface, the same as a malformed
     * filter uuid: without this the date parser throws and the bot sees a 500 it cannot act on.
     */
    private function parseDate(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            throw new BadRequestHttpException('Malformed date.');
        }
    }

    /**
     * 404 rather than 403 for a shift the volunteer may not see, so the surface
     * does not confirm that drafts or staff-only shifts exist.
     */
    private function requireVisible(Shift $shift, \App\Entity\User $actor): void
    {
        if (!$this->visibility->isVisibleTo($shift, $actor)) {
            throw $this->createNotFoundException('Unknown shift.');
        }
    }

    /**
     * Why this volunteer cannot sign up, in the same words the web UI uses. Probed
     * against a type the shift actually asks for, since signUpError() needs one.
     */
    private function refusalReason(\App\Entity\User $actor, Shift $shift): string
    {
        $availability = $this->signup->availability($shift);
        if ($availability === []) {
            return $this->translator->trans('shift.refusal.not_open');
        }

        return $this->signup->signUpError($actor, $shift, $availability[0]['type'])
            ?? $this->translator->trans('shift.refusal.not_eligible');
    }

    /**
     * The requested type, or the only option when the shift needs just one - the
     * bot cannot ask a volunteer to choose between one thing.
     */
    private function resolveVolunteerType(?string $uuid, Shift $shift, \App\Entity\User $actor): ?VolunteerType
    {
        if ($uuid !== null) {
            return \Symfony\Component\Uid\Uuid::isValid($uuid)
                ? $this->volunteerTypes->findOneBy(['uuid' => $uuid])
                : null;
        }

        $options = $this->signup->signupOptions($shift, $actor);

        return \count($options) === 1 ? reset($options) : null;
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
