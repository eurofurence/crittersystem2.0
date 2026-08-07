<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftGroup;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Repository\DepartmentRepository;
use App\Entity\VolunteerType;
use App\Repository\LocationRepository;
use App\Repository\ShiftRepository;
use App\Repository\ShiftTaskRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use App\Service\Shift\PlannerDraftStore;
use App\Service\Shift\PlannerPresenter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Standard Planner: a per-department time×day grid where
 * managers paint, move, resize and delete draft shifts. Edits autosave as drafts
 * through {@see PlannerDraftStore}; publication is a separate step. All
 * mutations are permission-scoped to the shift's department.
 */
#[Route('/manage-shifts/planner')]
#[IsGranted('shift:manage')]
final class PlannerController extends AbstractController
{
    private const TASK_REQUIRED = 'Pick a shift task; a shift cannot be saved without one.';

    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly PlannerDraftStore $drafts,
        private readonly PlannerPresenter $presenter,
        private readonly DisplaySettings $display,
        private readonly LoggerInterface $logger,
        private readonly EventConfigStore $config,
        private readonly ShiftTaskRepository $tasks,
        private readonly \App\Service\Shift\ShiftTaskAccess $taskAccess,
        private readonly \App\Service\Shift\VolunteerTypeOrdering $typeOrder,
        private readonly \App\Repository\ShiftGroupRepository $shiftGroups,
        private readonly \App\Service\Shift\ShiftGroupAudit $audit,
        private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_manage_shifts_planner', methods: ['GET'])]
    public function index(Request $request, VolunteerTypeRepository $types, LocationRepository $locations): Response
    {
        // Only offer departments this manager may actually plan. shift:manage is scoped, so a
        // manager assigned to one department must not see (and 403 on) the others; an unscoped
        // holder or admin still sees them all.
        $planningDepartments = array_values(array_filter(
            $this->departments->findAllOrdered(),
            fn (Department $d) => !$d->isOrganizational() && $this->isGranted('shift:manage', $d),
        ));
        if ($planningDepartments === []) {
            return $this->render('planner/empty.html.twig');
        }

        /*
         * Planning is department-wide and destructive (publish, discard drafts, mass delete), so the
         * grid only loads once the manager has named the department. Defaulting to the first one and
         * rendering it immediately invited edits against a department nobody chose.
         */
        $department = ($id = $request->query->get('department'))
            ? $this->departments->findOneByUuid((string) $id)
            : null;
        if ($department === null) {
            return $this->render('planner/choose.html.twig', [
                'departments' => $planningDepartments,
            ]);
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);

        [$rangeStart, $rangeEnd] = $this->range();
        $tz = $this->display->timezone();
        $shifts = $this->shifts->findForDepartmentBetween($department, $rangeStart, $rangeEnd);

        $grid = $this->presenter->buildGrid(
            $rangeStart,
            $rangeEnd,
            $shifts,
            $tz,
            $this->config->getDate(EventConfigStore::KEY_EVENT_START),
            $this->config->getDate(EventConfigStore::KEY_EVENT_END),
        );

        return $this->render('planner/index.html.twig', [
            'department' => $department,
            'departments' => $planningDepartments,
            'grid' => $grid,
            'shiftTasks' => $this->taskAccess->forDepartment($this->tasks->findAllOrdered(), $department),
            'volunteerTypes' => $this->typeOrder->forDepartment($types->findAllOrderedWithDepartments(), $department),
            'locations' => $locations->findAllOrdered(),
            'audiences' => ShiftAudience::cases(),
            // Only this department's groups can be offered: a group and its shifts share one
            // department, which is what makes the batch assignment safe to scope.
            'shiftGroups' => $this->shiftGroups->findForDepartment($department),
            'timezone' => $tz->getName(),
        ]);
    }

    /**
     * Create a shift task for the department being planned.
     *
     * A manager with `shift:manage` on the department owns its tasks, so they may add one without
     * leaving the planner. The task belongs to that department; the global pool stays an admin's to
     * change.
     */
    #[Route('/task', name: 'app_manage_shifts_planner_task_create', methods: ['POST'])]
    public function createTask(Request $request): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            return $this->fail('Unknown department.');
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('planner_edit', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            return $this->fail('A shift task needs a name.');
        }

        // Names are unique per department, and a department also cannot shadow a global task.
        $clash = $this->tasks->findOneBy(['name' => $name, 'department' => $department])
            ?? $this->tasks->findOneBy(['name' => $name, 'department' => null]);
        if ($clash !== null) {
            return $this->fail(\sprintf('A shift task called "%s" is already available here.', $name));
        }

        $task = new ShiftTask($name);
        $task->setDepartment($department);
        $this->em->persist($task);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'id' => $task->getUuid(), 'name' => $task->getName()]);
    }

    #[Route('/paint', name: 'app_manage_shifts_planner_paint', methods: ['POST'])]
    public function paint(Request $request, LocationRepository $locations): Response
    {
        $data = $this->payload($request);
        $department = $this->departments->findOneByUuid((string) ($data['department'] ?? ''));
        if ($department === null) {
            return $this->fail('Unknown department.');
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('planner_paint', (string) ($data['_token'] ?? ''))) {
            return $this->fail('Invalid token.', 419);
        }

        $tz = $this->display->timezone();
        $intervals = [];
        foreach ($data['intervals'] ?? [] as $raw) {
            try {
                $intervals[] = [
                    new \DateTimeImmutable((string) $raw['start'], $tz),
                    new \DateTimeImmutable((string) $raw['end'], $tz),
                ];
            } catch (\Exception) {
                return $this->fail('Invalid interval.');
            }
        }
        if ($intervals === []) {
            return $this->fail('Nothing to create.');
        }

        $audience = ShiftAudience::tryFrom((string) ($data['audience'] ?? '')) ?? ShiftAudience::PUBLIC_VOLUNTEER;
        $task = $this->requiredTask((string) ($data['task'] ?? ''), $department);
        if (!$task instanceof ShiftTask) {
            return $this->fail(self::TASK_REQUIRED);
        }
        $location = $locations->findOneByUuid((string) ($data['location'] ?? ''));

        try {
            $shifts = $this->drafts->createConsolidated(
                $department,
                $intervals,
                $audience,
                $task,
                $location instanceof Location ? $location : null,
                $this->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        return new JsonResponse(['ok' => true, 'created' => \count($shifts)]);
    }

    #[Route('/create', name: 'app_manage_shifts_planner_create', methods: ['POST'])]
    public function create(Request $request, VolunteerTypeRepository $types, LocationRepository $locations): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            return $this->fail('Unknown department.');
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('planner_edit', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        $tz = $this->display->timezone();
        try {
            $start = new \DateTimeImmutable((string) $request->request->get('start'), $tz);
            $end = new \DateTimeImmutable((string) $request->request->get('end'), $tz);
        } catch (\Exception) {
            return $this->fail('Invalid start or end time.');
        }

        $audience = ShiftAudience::tryFrom((string) $request->request->get('audience', '')) ?? ShiftAudience::PUBLIC_VOLUNTEER;
        $task = $this->requiredTask((string) $request->request->get('task'), $department);
        if (!$task instanceof ShiftTask) {
            return $this->fail(self::TASK_REQUIRED);
        }
        $location = $locations->findOneByUuid((string) $request->request->get('location'));

        try {
            $shift = $this->drafts->createDraft(
                $department,
                $start,
                $end,
                $audience,
                $task,
                $location instanceof Location ? $location : null,
                $this->user(),
                trim((string) $request->request->get('title')) ?: null,
                trim((string) $request->request->get('description')) ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $shift->setRequireCheckin($request->request->getBoolean('require_checkin'));
        $this->applyNeededTypes($shift, $request, $types);

        return new JsonResponse(['ok' => true, 'id' => $shift->getUuid()]);
    }

    // No route requirement here: the client generates this URL with an `__ID__` placeholder and
    // substitutes the shift uuid at runtime, so the placeholder must pass URL generation. The
    // uuid lookup is still enforced by MapEntity below (a non-uuid simply 404s).
    #[Route('/shift/{id}/panel', name: 'app_manage_shifts_planner_panel', methods: ['GET'])]
    public function panel(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, VolunteerTypeRepository $types, LocationRepository $locations): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift->getDepartment());

        return $this->render('planner/_panel_single.html.twig', [
            'shift' => $shift,
            'audiences' => ShiftAudience::cases(),
            'shiftTasks' => $this->taskAccess->forDepartment($this->tasks->findAllOrdered(), $shift->getDepartment()),
            'locations' => $locations->findAllOrdered(),
            'volunteerTypes' => $this->typeOrder->forDepartment($types->findAllOrderedWithDepartments(), $shift->getDepartment()),
            'timezone' => $this->display->timezone()->getName(),
        ]);
    }

    #[Route('/shift/{id}/edit', name: 'app_manage_shifts_planner_edit', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, VolunteerTypeRepository $types, LocationRepository $locations): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift->getDepartment());
        if (!$this->isCsrfTokenValid('planner_edit', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        $tz = $this->display->timezone();
        $fields = [];
        if ($request->request->has('title')) {
            $fields['title'] = trim((string) $request->request->get('title'));
        }
        if ($request->request->has('description')) {
            $fields['description'] = trim((string) $request->request->get('description'));
        }
        if ($request->request->has('audience')) {
            $fields['audience'] = ShiftAudience::tryFrom((string) $request->request->get('audience')) ?? $shift->getAudience();
        }
        if ($request->request->has('task')) {
            $task = $this->requiredTask((string) $request->request->get('task'), $shift->getDepartment());
            if (!$task instanceof ShiftTask) {
                return $this->fail(self::TASK_REQUIRED);
            }
            $fields['task'] = $task;
        }
        if ($request->request->has('location')) {
            $fields['location'] = $locations->findOneByUuid((string) $request->request->get('location'));
        }
        if ($request->request->has('start') && $request->request->has('end')) {
            try {
                $fields['startsAt'] = new \DateTimeImmutable((string) $request->request->get('start'), $tz);
                $fields['endsAt'] = new \DateTimeImmutable((string) $request->request->get('end'), $tz);
            } catch (\Exception) {
                return $this->fail('Invalid start or end time.');
            }
        }
        $fields['requireCheckin'] = $request->request->getBoolean('require_checkin');

        try {
            $this->drafts->updateDetails($shift, $fields, $this->user());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        $this->applyNeededTypes($shift, $request, $types);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/publish', name: 'app_manage_shifts_planner_publish', methods: ['POST'])]
    #[IsGranted('shift:publish')]
    public function publish(Request $request, \App\Service\Shift\PublicationService $publisher): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            return $this->fail('Unknown department.');
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('planner_publish', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        try {
            $result = $publisher->publishDepartmentDrafts($department, [], $this->user());
        } catch (\App\Exception\StaleWriteException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        if (!$result->isSuccessful()) {
            return new JsonResponse(['ok' => false, 'errors' => $result->errors, 'invalid' => $result->invalidUuids], 422);
        }

        return new JsonResponse([
            'ok' => true,
            'published' => $result->publishedCount(),
            'warnings' => $result->warnings,
        ]);
    }

    #[Route('/batch', name: 'app_manage_shifts_planner_batch', methods: ['POST'])]
    public function batch(Request $request, VolunteerTypeRepository $types): Response
    {
        if (!$this->isCsrfTokenValid('planner_edit', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        $selection = $this->selectedShifts($request);
        /*
         * Cast rather than getInt(): the batch form's number inputs post an empty string when the
         * manager leaves them blank, which is the normal case when they only want to change the task
         * or the group, and getInt() rejects that as a malformed request rather than reading it as
         * "not set".
         */
        $duration = (int) $request->request->get('duration_minutes');
        $needType = $types->findOneByUuid((string) $request->request->get('needed_type'));
        $needCount = (int) $request->request->get('needed_count');
        $taskId = (string) $request->request->get('task');
        $groupChoice = (string) $request->request->get('shift_group', '');

        /*
         * Everything is validated against the whole selection first. Rejecting halfway through would
         * leave the shifts already visited changed and the rest not, with nothing on screen saying
         * which were which.
         */
        foreach ($selection as $shift) {
            // A blank picker leaves each shift's own task alone; choosing one replaces it.
            if ($taskId !== '' && !$this->requiredTask($taskId, $shift->getDepartment()) instanceof ShiftTask) {
                return $this->fail(self::TASK_REQUIRED);
            }
        }

        try {
            $group = $this->resolveGroupChoice($groupChoice, $selection);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        // Adding shifts to a populated group can leave volunteers on part of a commitment. The
        // management screen refuses until the manager confirms; the planner must not be a way around
        // that check, so it asks the same question before touching anything.
        if ($group instanceof ShiftGroup && !$request->request->getBoolean('confirm')) {
            $partial = $this->audit->partiallyAssignedCount(array_values(array_unique(
                array_merge($this->audit->membersOf($group), $selection),
                \SORT_REGULAR,
            )));
            if ($partial > 0) {
                return new JsonResponse([
                    'ok' => false,
                    'confirm' => $this->translator->trans('planner.batch.group.confirm_partial', ['%count%' => $partial]),
                ]);
            }
        }

        $applied = 0;
        foreach ($selection as $shift) {
            if ($duration >= PlannerDraftStore::MIN_DURATION_MINUTES) {
                $this->drafts->setDuration($shift, $duration, $this->user());
            }
            if ($taskId !== '') {
                $this->drafts->updateDetails($shift, ['task' => $this->requiredTask($taskId, $shift->getDepartment())], $this->user());
            }
            if ($groupChoice !== '') {
                $this->drafts->updateDetails($shift, ['shiftGroup' => $group], $this->user());
            }
            if ($needType instanceof VolunteerType) {
                $this->drafts->setNeededVolunteerType($shift, $needType, $needCount);
            }
            ++$applied;
        }

        return new JsonResponse(['ok' => true, 'applied' => $applied]);
    }

    /**
     * Create a shift group for the current selection, in one step.
     *
     * Naming the group is the whole point of the gesture, so this creates it and puts the selected
     * shifts in it rather than leaving an empty group behind for a manager who forgets to press
     * Apply. The other batch fields are independent and still need Apply.
     */
    #[Route('/shift-group', name: 'app_manage_shifts_planner_group_create', methods: ['POST'])]
    public function createShiftGroup(Request $request): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            return $this->fail('Unknown department.');
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('planner_edit', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            return $this->fail($this->translator->trans('planner.batch.group.blank'));
        }
        if ($this->shiftGroups->findOneBy(['department' => $department, 'name' => $name]) !== null) {
            return $this->fail($this->translator->trans('planner.batch.group.duplicate', ['%name%' => $name]));
        }

        $selection = $this->selectedShifts($request);
        // A group and its shifts share one department, because shift:manage is scoped by department
        // and a group spanning two would have none to check against. ids[] is user input, so this is
        // checked here and not inferred from the grid the selection came from.
        foreach ($selection as $shift) {
            if ($shift->getDepartment() !== $department) {
                return $this->fail($this->translator->trans('planner.batch.group.other_department'));
            }
        }

        if ($selection !== [] && !$request->request->getBoolean('confirm')) {
            $partial = $this->audit->partiallyAssignedCount($selection);
            if ($partial > 0) {
                return new JsonResponse([
                    'ok' => false,
                    'confirm' => $this->translator->trans('planner.batch.group.confirm_partial', ['%count%' => $partial]),
                ]);
            }
        }

        $group = new ShiftGroup($department, $name);
        $this->em->persist($group);
        $this->em->flush();

        foreach ($selection as $shift) {
            $this->drafts->updateDetails($shift, ['shiftGroup' => $group], $this->user());
        }

        return new JsonResponse([
            'ok' => true,
            'id' => $group->getUuid(),
            'name' => $group->getName(),
            'assigned' => \count($selection),
        ]);
    }

    /**
     * The group the batch form asked for: null to leave each shift alone, null-with-clear to take
     * them out, or the group itself.
     *
     * @param list<Shift> $selection
     *
     * @throws \InvalidArgumentException when the choice cannot be applied to this selection
     */
    private function resolveGroupChoice(string $choice, array $selection): ?ShiftGroup
    {
        if ($choice === '' || $choice === 'none') {
            return null;
        }

        $group = $this->shiftGroups->findOneByUuid($choice);
        if (!$group instanceof ShiftGroup) {
            throw new \InvalidArgumentException($this->translator->trans('planner.batch.group.unknown'));
        }

        // ids[] admits any shift this manager may manage, which can span departments even though the
        // grid shows one. Without this check the planner would be the one way to build a
        // cross-department group, and every scoped permission check against it afterwards would have
        // no authoritative department to use.
        foreach ($selection as $shift) {
            if ($shift->getDepartment() !== $group->getDepartment()) {
                throw new \InvalidArgumentException($this->translator->trans('planner.batch.group.other_department'));
            }
        }

        return $group;
    }

    /**
     * Delete every selected shift. Unlike discarding drafts this also removes published shifts, so
     * the caller confirms with the count first; assignments go with the shift.
     */
    #[Route('/batch/delete', name: 'app_manage_shifts_planner_batch_delete', methods: ['POST'])]
    public function batchDelete(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('planner_edit', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        $deleted = 0;
        foreach ($this->selectedShifts($request) as $shift) {
            $this->drafts->delete($shift);
            ++$deleted;
        }

        return new JsonResponse(['ok' => true, 'deleted' => $deleted]);
    }

    /**
     * Delete every draft shift of a department in one step, so a planning run that went wrong can be
     * cleared without picking the drafts off the grid one by one. Published shifts are never
     * touched: they may already carry assignments people are counting on.
     */
    #[Route('/drafts/discard', name: 'app_manage_shifts_planner_discard_drafts', methods: ['POST'])]
    public function discardDrafts(Request $request): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            return $this->fail('Unknown department.');
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('planner_discard', (string) $request->request->get('_token'))) {
            return $this->fail('Invalid token.', 419);
        }

        $drafts = $this->shifts->findBy(['department' => $department, 'state' => ShiftState::DRAFT->value]);
        foreach ($drafts as $draft) {
            $this->drafts->delete($draft);
        }

        return new JsonResponse(['ok' => true, 'deleted' => \count($drafts)]);
    }

    #[Route('/shift/{id}/move', name: 'app_manage_shifts_planner_move', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function move(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift->getDepartment());
        $data = $this->payload($request);
        if (!$this->isCsrfTokenValid('planner_edit', (string) ($data['_token'] ?? ''))) {
            return $this->fail('Invalid token.', 419);
        }
        $tz = $this->display->timezone();

        try {
            $this->drafts->reschedule(
                $shift,
                new \DateTimeImmutable((string) ($data['start'] ?? ''), $tz),
                new \DateTimeImmutable((string) ($data['end'] ?? ''), $tz),
                $this->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (\Exception $e) {
            /*
             * Only a rejected value is the caller's fault. Echoing any other failure back as if it were
             * one tells a manager their drag was invalid when the database is down, and leaves no trace.
             */
            $this->logger->error('Rescheduling a draft shift failed: {reason}', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $this->fail('The shift could not be moved. Please try again.', 500);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/shift/{id}/delete', name: 'app_manage_shifts_planner_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift->getDepartment());
        // Delete is triggered both from the JS grid (JSON body) and the panel
        // form (form-encoded), so accept the token from either.
        $token = (string) ($request->request->get('_token') ?? $this->payload($request)['_token'] ?? '');
        if (!$this->isCsrfTokenValid('planner_edit', $token)) {
            return $this->fail('Invalid token.', 419);
        }

        $this->drafts->delete($shift);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * The shifts named by `ids[]` that this manager may actually plan. Out-of-scope shifts are
     * dropped silently rather than reported, so the response cannot be used to probe which shift
     * uuids exist in departments the caller does not manage.
     *
     * @return list<Shift>
     */
    private function selectedShifts(Request $request): array
    {
        $selection = [];
        foreach ((array) $request->request->all('ids') as $uuid) {
            $shift = $this->shifts->findOneByUuid((string) $uuid);
            if ($shift !== null && $this->isGranted('shift:manage', $shift->getDepartment())) {
                $selection[] = $shift;
            }
        }

        return $selection;
    }

    /**
     * Resolve a shift task the department is actually offered, or null. Every planner surface that
     * creates or saves a shift requires one, so a missing, unknown or foreign task is a rejection
     * rather than a silent "no task".
     */
    private function requiredTask(string $uuid, ?Department $department): ?ShiftTask
    {
        $task = $this->tasks->findOneByUuid($uuid);
        if (!$task instanceof ShiftTask) {
            return null;
        }

        return $this->taskAccess->forDepartment([$task], $department) === [] ? null : $task;
    }

    /**
     * Apply the `needed[typeId] = count` staffing requirements from a create/edit
     * form to the shift.
     */
    private function applyNeededTypes(Shift $shift, Request $request, VolunteerTypeRepository $types): void
    {
        /** @var array<int|string, mixed> $needed */
        $needed = (array) $request->request->all('needed');
        foreach ($needed as $typeUuid => $count) {
            $type = $types->findOneByUuid((string) $typeUuid);
            if ($type instanceof VolunteerType) {
                $this->drafts->setNeededVolunteerType($shift, $type, (int) $count);
            }
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function user(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function range(): array
    {
        $start = $this->config->getDate(EventConfigStore::KEY_BUILDUP_START)
            ?? $this->config->getDate(EventConfigStore::KEY_EVENT_START);
        $end = $this->config->getDate(EventConfigStore::KEY_TEARDOWN_END)
            ?? $this->config->getDate(EventConfigStore::KEY_EVENT_END);

        // Fall back to a sensible window around today when the event is unconfigured.
        $now = new \DateTimeImmutable('today');
        $start ??= $now;
        $end ??= $start->modify('+3 days');
        // Make the end exclusive of the day after, so the last day is included.
        return [$start, $end->modify('+1 day')];
    }
}
