<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Exception\CapacityConflictException;
use App\Repository\DepartmentRepository;
use App\Service\Assignment\EventHoursGuard;
use App\Service\Shift\ShiftGroupResolver;
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
use Symfony\Component\Translation\TranslatableMessage;

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
        private readonly ShiftGroupResolver $groups,
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

    /**
     * Every row this user may apply to in one department.
     *
     * Replaces the per-row frame each shift used to carry: one signal on the department's topic
     * refreshes the group in a single request, rather than one request per row per timer tick.
     *
     * No department check is needed, and none would help: applicableShifts() filters by
     * ShiftVisibilityResolver, so a department the caller may see nothing in renders as an empty
     * list rather than refusing and thereby confirming it exists.
     */
    #[Route('/manage-shifts/apply/department/{id}', name: 'app_manage_shifts_apply_department', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function departmentRows(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('shift_manager/_apply_rows.html.twig', [
            'rows' => $this->applications->applicableShifts($department, $user),
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
            $type = $options[(string) $request->request->get('volunteer_type')] ?? (\count($options) === 1 ? reset($options) : null);
            if ($type === null) {
                $this->addFlash('danger', new TranslatableMessage('shift_manager.flash.choose_role'));
            } elseif ($this->hoursGuard->wouldExceedGroup($user, $shift) && !$request->request->getBoolean('acknowledge_hours')) {
                // Self-application beyond the recommended hours needs explicit
                // acknowledgement.
                $this->addFlash('warning', new TranslatableMessage(
                    'shift_manager.flash.over_hours',
                    ['%count%' => $this->hoursGuard->recommendedMax()],
                ));
            } else {
                try {
                    if ($this->hoursGuard->wouldExceedGroup($user, $shift)) {
                        $this->hoursGuard->acknowledgeSelfApplication($user, $shift);
                    }
                    $this->signup->signUp(
                        $user,
                        $shift,
                        $type,
                        (string) $request->request->get('comment') ?: null,
                        $this->typeChoices($request),
                        $request->request->getBoolean('acknowledge_hours'),
                    );
                    $held = \count($this->groups->entriesFor($shift, $user));
                    $this->addFlash('success', $held > 1
                        ? new TranslatableMessage('shift_manager.flash.applied_group', ['%name%' => $shift->getTitle(), '%count%' => $held])
                        : new TranslatableMessage('shift_manager.flash.applied', ['%name%' => $shift->getTitle()]));
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

    /**
     * Per-member role choices from the confirmation modal, as member shift uuid => volunteer type uuid.
     *
     * Only the shape is validated here; which roles the volunteer may take is decided by the sign-up
     * service against live data, never by what the form posted.
     *
     * @return array<string, string>
     */
    private function typeChoices(Request $request): array
    {
        $choices = [];
        foreach ($request->request->all('group_type') as $uuid => $typeUuid) {
            if (\is_string($uuid) && \Symfony\Component\Uid\Uuid::isValid($uuid) && \is_string($typeUuid) && \Symfony\Component\Uid\Uuid::isValid($typeUuid)) {
                $choices[$uuid] = $typeUuid;
            }
        }

        return $choices;
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
                $this->addFlash('success', new TranslatableMessage('shift_manager.flash.application_cancelled'));
            }
        }

        return $this->redirectToRoute('app_manage_shifts_apply');
    }
}
