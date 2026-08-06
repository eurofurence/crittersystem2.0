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
use App\Service\DisplaySettings;
use App\Service\HoursCalculator;
use App\Service\Shift\ShiftGroupResolver;
use App\Service\Shift\ShiftGroupSignupService;
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
    ) {
    }

    #[IsGranted('shift:view')]
    #[Route('/shifts', name: 'app_shift_index', methods: ['GET'])]
    public function index(Request $request, LocationRepository $locationRepo, ShiftTaskRepository $shiftTaskRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $tz = $this->display->timezone();
        $days = $this->shifts->findUpcomingDays($tz);
        $selectedDate = $this->resolveDate((string) $request->query->get('date', ''), $days, $tz);
        $location = $locationRepo->findOneByUuid((string) $request->query->get('location'));
        $shiftTask = $shiftTaskRepo->findOneByUuid((string) $request->query->get('type'));
        $onlyAvailable = $request->query->getBoolean('available');
        $onlyMine = $request->query->getBoolean('mine');

        $byHour = [];
        foreach ($this->shifts->findForDay($selectedDate, $tz, $location, $shiftTask) as $shift) {
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
                // Only counted, not described: the card shows "part of N shifts" and the modal
                // fetches the detail, which is where the visibility filter runs.
                'groupSize' => \count($this->groups->membersFor($shift)),
            ];
        }

        return $this->render('shift/index.html.twig', [
            'days' => $days,
            'selectedDate' => $selectedDate,
            'byHour' => $byHour,
            'locations' => $locationRepo->findAllOrdered(),
            'shiftTasks' => $shiftTaskRepo->findAllOrdered(),
            'filters' => [
                'location' => $location !== null ? (string) $location->getUuid() : null,
                'type' => $shiftTask !== null ? (string) $shiftTask->getUuid() : null,
                'available' => $onlyAvailable,
                'mine' => $onlyMine,
            ],
        ]);
    }

    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}', name: 'app_shift_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // The volunteer browser only exposes published public shifts; a draft or
        // staff-only shift reached by id must not leak here.
        if (!$this->visibility->isVisibleTo($shift, $user)) {
//            throw $this->createNotFoundException();
            return $this->render('shift/not_found.html.twig');
        }

        return $this->render('shift/show.html.twig', [
            'shift' => $shift,
            'availability' => $this->signup->availability($shift),
            'myEntry' => $this->entries->findOneByShiftAndUser($shift, $user),
            'signupOptions' => $this->signup->signupOptions($shift, $user),
            // Siblings the viewer may see. A member they may not see is absent, and the plan behind
            // the modal refuses the whole group without naming it.
            'groupSiblings' => $this->groups->isFullyVisibleTo($shift, $user) ? $this->groups->siblingsOf($shift) : [],
        ]);
    }

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
            $type = $options[(string) $request->request->get('volunteer_type')] ?? null;

            if ($type === null) {
                $this->addFlash('danger', new TranslatableMessage('shift.flash.choose_type'));
            } else {
                try {
                    $created = $this->groupSignup->signUpGroup(
                        $user,
                        $shift,
                        $type,
                        $this->typeChoices($request),
                        (string) $request->request->get('comment') ?: null,
                        $request->request->getBoolean('acknowledge_hours'),
                    );
                    $this->addFlash('success', \count($created) > 1
                        ? new TranslatableMessage('shift.flash.signed_up_group', ['%count%' => \count($created)])
                        : new TranslatableMessage('shift.flash.signed_up', ['%name%' => $type->getName()]));
                } catch (CapacityConflictException $e) {
                    // The group filled up between rendering the modal and submitting it.
                    $this->addFlash('warning', $e->getMessage());
                } catch (\RuntimeException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->redirectToRefererOrShift($request, $shift);
    }

    /**
     * The confirmation body for a grouped shift: every member, the role on each, the capacity and the
     * hours the whole thing adds.
     *
     * Rendered here rather than built in the browser from a data attribute. Capacity and eligibility
     * are per viewer and move between the page render and the click, and the visibility filter has to
     * run on the server - a stale attribute could show a full shift as open, or carry a member this
     * viewer may not see.
     */
    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}/group', name: 'app_shift_group_modal', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function groupModal(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }

        $options = $this->signup->signupOptions($shift, $user);
        $typeUuid = (string) $request->query->get('volunteer_type');

        return $this->render('shift/_group_modal.html.twig', [
            'plan' => $this->signup->plan($user, $shift, $options[$typeUuid] ?? null),
            'mode' => $request->query->get('mode') === 'cancel' ? 'cancel' : 'apply',
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
     * @param string[] $days
     */
    private function resolveDate(string $dateParam, array $days, \DateTimeZone $tz): \DateTimeImmutable
    {
        if ($dateParam !== '') {
            try {
                return (new \DateTimeImmutable($dateParam, $tz))->setTime(12, 0);
            } catch (\Exception) {
                // fall through to defaults
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
