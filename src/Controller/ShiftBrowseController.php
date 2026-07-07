<?php

namespace App\Controller;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Repository\LocationRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use App\Repository\ShiftTypeRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\HoursCalculator;
use App\Service\ShiftSignupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Volunteer-facing shift browsing (filterable list, grouped by hour), sign-up/
 * cancellation, and "my shifts" — the ShiftManagerV2-style apply screen.
 */
final class ShiftBrowseController extends AbstractController
{
    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly ShiftSignupService $signup,
        private readonly HoursCalculator $hours,
    ) {
    }

    #[IsGranted('shift:view')]
    #[Route('/shifts', name: 'app_shift_index', methods: ['GET'])]
    public function index(Request $request, LocationRepository $locationRepo, ShiftTypeRepository $shiftTypeRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $days = $this->shifts->findUpcomingDays();
        $selectedDate = $this->resolveDate((string) $request->query->get('date', ''), $days);

        $location = ($lid = $request->query->getInt('location')) ? $locationRepo->find($lid) : null;
        $shiftType = ($tid = $request->query->getInt('type')) ? $shiftTypeRepo->find($tid) : null;
        $onlyAvailable = $request->query->getBoolean('available');
        $onlyMine = $request->query->getBoolean('mine');

        $byHour = [];
        foreach ($this->shifts->findForDay($selectedDate, $location, $shiftType) as $shift) {
            $availability = $this->signup->availability($shift);
            $status = $this->signup->eligibilityStatus($shift, $user);
            $relevant = $this->isRelevant($availability, $user);

            if ($onlyAvailable && $status !== 'available') {
                continue;
            }
            if ($onlyMine && !$relevant) {
                continue;
            }

            $hour = $shift->getStartsAt()->format('H:00');
            $byHour[$hour][] = [
                'shift' => $shift,
                'availability' => $availability,
                'status' => $status,
                'options' => $this->signup->signupOptions($shift, $user),
                'myEntry' => $this->entries->findOneByShiftAndUser($shift, $user),
            ];
        }

        return $this->render('shift/index.html.twig', [
            'days' => $days,
            'selectedDate' => $selectedDate,
            'byHour' => $byHour,
            'locations' => $locationRepo->findAllOrdered(),
            'shiftTypes' => $shiftTypeRepo->findAllOrdered(),
            'filters' => [
                'location' => $location?->getId(),
                'type' => $shiftType?->getId(),
                'available' => $onlyAvailable,
                'mine' => $onlyMine,
            ],
        ]);
    }

    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}', name: 'app_shift_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('shift/show.html.twig', [
            'shift' => $shift,
            'availability' => $this->signup->availability($shift),
            'myEntry' => $this->entries->findOneByShiftAndUser($shift, $user),
            'signupOptions' => $this->signup->signupOptions($shift, $user),
        ]);
    }

    #[IsGranted('shift:view')]
    #[Route('/shifts/{id}/signup', name: 'app_shift_signup', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function signUp(Request $request, Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('signup'.$shift->getId(), (string) $request->request->get('_token'))) {
            $options = $this->signup->signupOptions($shift, $user);
            $type = $options[(int) $request->request->get('volunteer_type')] ?? null;

            if ($type === null) {
                $this->addFlash('danger', 'Choose a volunteer type you can sign up as.');
            } else {
                $error = $this->signup->signUpError($user, $shift, $type);
                if ($error !== null) {
                    $this->addFlash('danger', $error);
                } else {
                    $this->signup->signUp($user, $shift, $type, (string) $request->request->get('comment') ?: null);
                    $this->addFlash('success', \sprintf('Signed up as %s.', $type->getName()));
                }
            }
        }

        return $this->redirectToRefererOrShift($request, $shift);
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
    #[Route('/shifts/entries/{id}/cancel', name: 'app_shift_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(Request $request, ShiftEntry $entry): Response
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
                $this->addFlash('success', 'Sign-up cancelled.');
            }
        }

        return $this->redirectToRefererOrShift($request, $entry->getShift());
    }

    /** @param array<string, string> $days */
    private function resolveDate(string $dateParam, array $days): \DateTimeImmutable
    {
        if ($dateParam !== '') {
            try {
                return new \DateTimeImmutable($dateParam);
            } catch (\Exception) {
                // fall through to defaults
            }
        }

        return new \DateTimeImmutable($days[0] ?? 'today');
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
        if ($referer !== '' && str_contains($referer, '/shifts') && !str_contains($referer, '/shifts/'.$shift->getId())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_shift_show', ['id' => $shift->getId()]);
    }
}
