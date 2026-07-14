<?php

namespace App\Controller;

use App\Entity\Shift;
use App\Entity\User;
use App\Exception\CapacityConflictException;
use App\Repository\DepartmentRepository;
use App\Service\Assignment\EventHoursGuard;
use App\Service\Shift\ShiftVisibilityResolver;
use App\Service\Shift\StaffApplicationService;
use App\Service\ShiftSignupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff Shift Manager module. A permission-scoped landing plus
 * the staff shift application view: departments with open staff shifts grouped
 * into member and other departments, live capacity via polling, and
 * transactional apply/cancel that surfaces conflicts without a full-page refresh.
 */
#[IsGranted('manageshifts:view')]
final class ShiftManagerController extends AbstractController
{
    public function __construct(
        private readonly StaffApplicationService $applications,
        private readonly ShiftVisibilityResolver $visibility,
        private readonly ShiftSignupService $signup,
        private readonly EventHoursGuard $hoursGuard,
    ) {
    }

    #[Route('/manage-shifts', name: 'app_manage_shifts', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('shift_manager/index.html.twig');
    }

    #[Route('/manage-shifts/apply', name: 'app_manage_shifts_apply', methods: ['GET'])]
    public function apply(Request $request, DepartmentRepository $departments): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $groups = $this->applications->departmentGroups($user);

        // A shift invitation link filters to one department.
        if ($uuid = $request->query->get('department')) {
            $keep = static fn (array $entry) => (string) $entry['department']->getUuid() === $uuid;
            $groups = [
                'member' => array_values(array_filter($groups['member'], $keep)),
                'other' => array_values(array_filter($groups['other'], $keep)),
            ];
        }

        return $this->render('shift_manager/apply.html.twig', [
            'groups' => $groups,
            'plannedHours' => $this->hoursGuard->plannedHours($user),
            'recommendedMax' => $this->hoursGuard->recommendedMax(),
            'overHours' => $this->hoursGuard->overBy($user),
        ]);
    }

    #[Route('/manage-shifts/apply/{id}/frame', name: 'app_manage_shifts_apply_frame', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function frame(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }

        return $this->render('shift_manager/_apply_frame.html.twig', [
            'row' => $this->applications->shiftRow($shift, $user),
            'signupOptions' => $this->signup->signupOptions($shift, $user),
        ]);
    }

    #[Route('/manage-shifts/apply/{id}', name: 'app_manage_shifts_apply_do', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function doApply(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('apply'.$shift->getId(), (string) $request->request->get('_token'))) {
            $options = $this->signup->signupOptions($shift, $user);
            $type = $options[$request->request->getInt('volunteer_type')] ?? (\count($options) === 1 ? reset($options) : null);
            if ($type === null) {
                $this->addFlash('danger', 'Choose a role you are eligible to apply as.');
            } elseif ($this->hoursGuard->wouldExceed($user, $shift) && !$request->request->getBoolean('acknowledge_hours')) {
                // Self-application beyond the recommended hours needs explicit
                // acknowledgement.
                $this->addFlash('warning', \sprintf(
                    'This shift would take you past the recommended %d planned hours. Tick "I understand" to apply anyway.',
                    $this->hoursGuard->recommendedMax(),
                ));
            } else {
                try {
                    if ($this->hoursGuard->wouldExceed($user, $shift)) {
                        $this->hoursGuard->acknowledgeSelfApplication($user, $shift);
                    }
                    $this->signup->signUp($user, $shift, $type, (string) $request->request->get('comment') ?: null);
                    $this->addFlash('success', \sprintf('Applied to "%s".', $shift->getTitle()));
                } catch (CapacityConflictException $e) {
                    // Stale UI: capacity changed underneath. Report and let the
                    // polling frame refresh the live state.
                    $this->addFlash('warning', $e->getMessage());
                } catch (\RuntimeException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->redirectToRoute('app_manage_shifts_apply');
    }

    #[Route('/manage-shifts/apply/{id}/cancel', name: 'app_manage_shifts_apply_cancel', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function cancel(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, \App\Repository\ShiftEntryRepository $entries): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $entry = $entries->findOneByShiftAndUser($shift, $user);
        if ($entry !== null && $this->isCsrfTokenValid('cancel'.$shift->getId(), (string) $request->request->get('_token'))) {
            $error = $this->signup->cancelError($entry, false);
            if ($error !== null) {
                $this->addFlash('danger', $error);
            } else {
                $this->signup->cancel($entry);
                $this->addFlash('success', 'Application cancelled.');
            }
        }

        return $this->redirectToRoute('app_manage_shifts_apply');
    }
}
