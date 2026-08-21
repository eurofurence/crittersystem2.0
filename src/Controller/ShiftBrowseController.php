<?php

namespace App\Controller;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Exception\CapacityConflictException;
use App\Repository\LocationRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use App\Repository\ShiftTaskRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\CheckInMessageProvider;
use App\Service\DisplaySettings;
use App\Service\HoursCalculator;
use App\Service\Shift\CheckInPolicy;
use App\Service\Shift\ShiftDossierPresenter;
use App\Service\Shift\ShiftEligibility;
use App\Service\Shift\ShiftFilterMemory;
use App\Service\Shift\ShiftGroupResolver;
use App\Service\Shift\ShiftGroupSignupService;
use App\Service\Shift\ShiftRequirementsResolver;
use App\Service\Shift\ShiftVisibilityResolver;
use App\Service\ShiftSignupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Volunteer-facing shift browsing (filterable list, grouped by hour), sign-up/
 * cancellation, and "my shifts" - the ShiftManagerV2-style apply screen.
 */
final class ShiftBrowseController extends AbstractController
{
    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly ShiftSignupService $signup,
        private readonly ShiftGroupSignupService $groupSignup,
        private readonly HoursCalculator $hours,
        private readonly ShiftVisibilityResolver $visibility,
        private readonly ShiftGroupResolver $groups,
        private readonly DisplaySettings $display,
        private readonly ShiftEligibility $eligibility,
        private readonly CheckInPolicy $checkIn,
        private readonly CheckInMessageProvider $checkInMessage,
        private readonly ShiftFilterMemory $filterMemory,
        private readonly ShiftDossierPresenter $dossier,
    ) {
    }

    /**
     * The browsable shift list for one day, grouped by local start hour.
     *
     * The filters are restored from the viewer's last visit when the request states none of its
     * own, so coming back to this page shows what they were looking at. A URL that carries filters
     * always wins, so a shared or bookmarked link is never overridden by the recipient's own last
     * choice. The day is never restored: it expires, and landing somebody on yesterday reads as an
     * empty page rather than as a remembered filter.
     *
     * The eligibility rules are preloaded for the whole day and dropped again in a `finally`: what
     * they hold is entities, the volunteer's own entries and the live capacity counts, so a preload
     * left standing would answer the next caller with this render's data.
     *
     * A grouped shift is counted here and never described: the card says "part of N shifts" and the
     * modal fetches the detail, which is where the visibility filter runs.
     */
    #[IsGranted('shift:view')]
    #[Route('/shifts', name: 'app_shift_index', methods: ['GET'])]
    public function index(Request $request, LocationRepository $locationRepo, ShiftTaskRepository $shiftTaskRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $tz = $this->display->timezone();
        $days = $this->shifts->findUpcomingDays($tz);
        $selectedDate = $this->resolveDate((string) $request->query->get('date', ''), $days, $tz);

        $filters = $this->filterMemory->resolve($user, ShiftFilterMemory::SURFACE_BROWSE, $request->query->all());
        $location = $locationRepo->findOneByUuid((string) ($filters['location'] ?? ''));
        $shiftTask = $shiftTaskRepo->findOneByUuid((string) ($filters['type'] ?? ''));
        $onlyAvailable = isset($filters['available']);
        $onlyMine = isset($filters['mine']);

        $shifts = $this->shifts->findForDay($selectedDate, $tz, $location, $shiftTask);

        $byHour = [];
        $this->eligibility->warmUp($user, $shifts);
        try {
            foreach ($shifts as $shift) {
                $availability = $this->signup->availability($shift);
                $status = $this->signup->eligibilityStatus($shift, $user);
                $relevant = $this->isRelevant($availability, $user);

                if ($onlyAvailable && $status !== 'available') {
                    continue;
                }
                if ($onlyMine && !$relevant) {
                    continue;
                }

                $hour = $shift->getStartsAt()->setTimezone($tz)->format('H:00');
                $byHour[$hour][] = [
                    'shift' => $shift,
                    'availability' => $availability,
                    'status' => $status,
                    'options' => $this->signup->signupOptions($shift, $user),
                    'myEntry' => $this->entries->findOneByShiftAndUser($shift, $user),
                    'groupSize' => \count($this->groups->membersFor($shift)),
                ];
            }
        } finally {
            $this->eligibility->coolDown();
        }

        return $this->render('shift/index.html.twig', [
            'days' => $days,
            'selectedDate' => $selectedDate,
            'byHour' => $byHour,
            'checkInMessage' => $this->checkIn->isCheckedIn($user) ? null : $this->checkInMessage->message(),
            'locations' => $locationRepo->findAllOrderedByPath(),
            'shiftTasks' => $shiftTaskRepo->findAllOrdered(),
            'filters' => [
                'location' => $location !== null ? (string) $location->getUuid() : null,
                'type' => $shiftTask !== null ? (string) $shiftTask->getUuid() : null,
                'available' => $onlyAvailable,
                'mine' => $onlyMine,
            ],
        ]);
    }

    /**
     * The volunteer browser exposes only shifts this viewer may see: a draft or staff-only shift
     * reached by id answers 404, so the page cannot be used to confirm that it exists.
     *
     * Group siblings are listed only when the whole group is visible to the viewer. A member they
     * may not see is absent from the list, and the plan behind the modal then refuses the whole
     * group without naming it.
     */
    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}', name: 'app_shift_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }

        return $this->render('shift/show.html.twig', [
            'shift' => $shift,
            'myEntry' => $this->entries->findOneByShiftAndUser($shift, $user),
            'signupOptions' => $this->signup->signupOptions($shift, $user),
            'dossier' => $this->dossier->present($shift, $user),
        ]);
    }

    /**
     * The dossier on its own, for the dialog that opens it from a shift list.
     *
     * Same shift, same viewer, same filtering as the page: {@see ShiftDossierPresenter} decides what
     * this answers with, so the dialog can never show what the page would withhold. A shift the
     * viewer may not see answers 404 here too, or the dialog would confirm it exists.
     *
     * This is a fragment and is injected into a page, so it must never be answered with a whole
     * document. Nothing may be allowed to redirect it: fetch follows redirects, and the 200 that
     * comes back would carry an entire page into the dialog body.
     */
    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}/info', name: 'app_shift_info', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function info(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }

        return $this->render('shift/_dossier.html.twig', [...$this->dossier->present($shift, $user), 'heading' => true]);
    }

    /**
     * Sign up for a shift, and for every member of its group.
     *
     * The role arrives one of two ways. A page that carries its own dropdown posts
     * `volunteer_type`; the dialog, which is where the browse list asks, posts the answer under
     * `group_type` keyed by shift uuid, the origin included. Either way it is looked up in
     * `signupOptions()`, so what the form posts never widens what the volunteer may take.
     *
     * A capacity conflict means the group filled between the modal being rendered and submitted, so
     * it is reported as a warning rather than an error.
     */
    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}/signup', name: 'app_shift_signup', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function signUp(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('signup'.$shift->getId(), (string) $request->request->get('_token'))) {
            $options = $this->signup->signupOptions($shift, $user);
            $choices = $this->typeChoices($request);
            $requested = (string) $request->request->get('volunteer_type')
                ?: ($choices[(string) $shift->getUuid()] ?? '');
            $type = $options[$requested] ?? null;

            if ($type === null) {
                $this->addFlash('danger', new TranslatableMessage('shift.flash.choose_type'));
            } else {
                try {
                    $created = $this->groupSignup->signUpGroup(
                        $user,
                        $shift,
                        $type,
                        $choices,
                        (string) $request->request->get('comment') ?: null,
                        $request->request->getBoolean('acknowledge_hours'),
                    );
                    $this->addFlash('success', \count($created) > 1
                        ? new TranslatableMessage('shift.flash.signed_up_group', ['%count%' => \count($created)])
                        : new TranslatableMessage('shift.flash.signed_up', ['%name%' => $type->getName()]));
                } catch (CapacityConflictException $e) {
                    $this->addFlash('warning', $e->getMessage());
                } catch (\RuntimeException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->redirectToRefererOrShift($request, $shift);
    }

    /**
     * The body of the shift dialog: every shift the action covers, the role on each, the capacity,
     * the hours it adds, and what the viewer would need to qualify for the roles they cannot take.
     * A shift with no group is a group of one, so the same body serves both.
     *
     * Rendered here rather than built in the browser from a data attribute. Capacity and eligibility
     * are per viewer and move between the page render and the click, and the visibility filter has to
     * run on the server - a stale attribute could show a full shift as open, or carry a member this
     * viewer may not see.
     *
     * The requirements are keyed by the members the plan exposes, never by the group: a group
     * holding a shift this viewer may not see produces no member list at all, and describing that
     * shift's roles here would confirm it exists.
     */
    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}/group', name: 'app_shift_group_modal', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function groupModal(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, ShiftRequirementsResolver $requirements): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }

        $options = $this->signup->signupOptions($shift, $user);
        $typeUuid = (string) $request->query->get('volunteer_type');
        $plan = $this->signup->plan($user, $shift, $options[$typeUuid] ?? null);

        $byMember = [];
        foreach ($plan->members as $member) {
            $byMember[(string) $member->shift->getUuid()] = $requirements->forShift($member->shift, $user);
        }

        return $this->render('shift/_group_modal.html.twig', [
            'plan' => $plan,
            'mode' => $request->query->get('mode') === 'cancel' ? 'cancel' : 'apply',
            'requirements' => $byMember,
        ]);
    }

    #[IsGranted('shift:self')]
    #[Route('/my-shifts', name: 'app_my_shifts', methods: ['GET'])]
    public function myShifts(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $entries = $this->entries->findByUserOrdered($user);
        $perEntryHours = [];
        foreach ($entries as $entry) {
            $perEntryHours[$entry->getId()] = $this->hours->entryHours($entry);
        }

        return $this->render('shift/my_shifts.html.twig', [
            'entries' => $entries,
            'perEntryHours' => $perEntryHours,
            'totalHours' => $this->hours->totalForUser($user),
        ]);
    }

    #[IsGranted('shift:self')]
    #[Route('/shifts/entries/{id}/cancel', name: 'app_shift_cancel', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function cancel(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftEntry $entry): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($entry->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('cancel'.$entry->getId(), (string) $request->request->get('_token'))) {
            $error = $this->signup->cancelError($entry, false);
            if ($error !== null) {
                $this->addFlash('danger', $error);
            } else {
                $this->signup->cancel($entry);
                $this->addFlash('success', new TranslatableMessage('shift.flash.signup_cancelled'));
            }
        }

        return $this->redirectToRefererOrShift($request, $entry->getShift());
    }

    /**
     * Per-member role choices from the confirmation modal, as member shift uuid => volunteer type uuid.
     *
     * Only the shape is validated here. Which roles a volunteer may actually take on each member is
     * decided by the sign-up service against the live data, never by what the form posted.
     *
     * @return array<string, string>
     */
    private function typeChoices(Request $request): array
    {
        $raw = $request->request->all('group_type');
        $choices = [];
        foreach ($raw as $uuid => $typeUuid) {
            if (\is_string($uuid) && Uuid::isValid($uuid) && \is_string($typeUuid) && Uuid::isValid($typeUuid)) {
                $choices[$uuid] = $typeUuid;
            }
        }

        return $choices;
    }

    /**
     * The selected calendar day, anchored at local noon in the display timezone so that
     * both the day window ({@see ShiftRepository::findForDay()}) and the rendered date land
     * on the intended day rather than drifting across a UTC midnight boundary.
     *
     * A date parameter that cannot be parsed falls back to the first available day instead of
     * failing the request.
     *
     * @param string[] $days
     */
    private function resolveDate(string $dateParam, array $days, \DateTimeZone $tz): \DateTimeImmutable
    {
        if ($dateParam !== '') {
            try {
                return (new \DateTimeImmutable($dateParam, $tz))->setTime(12, 0);
            } catch (\Exception) {
            }
        }

        return (new \DateTimeImmutable($days[0] ?? 'today', $tz))->setTime(12, 0);
    }

    /**
     * Whether the user is a confirmed member of any role this shift needs.
     *
     * @param list<array{type: \App\Entity\VolunteerType, needed: int, assigned: int}> $availability
     */
    private function isRelevant(array $availability, User $user): bool
    {
        foreach ($availability as $row) {
            if ($this->memberships->isConfirmedMember($user, $row['type'])) {
                return true;
            }
        }

        return false;
    }

    private function redirectToRefererOrShift(Request $request, Shift $shift): Response
    {
        $referer = (string) $request->headers->get('referer');
        if ($referer !== '' && str_contains($referer, '/shifts') && !str_contains($referer, '/shifts/'.$shift->getUuid())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_shift_show', ['id' => $shift->getUuid()]);
    }
}
