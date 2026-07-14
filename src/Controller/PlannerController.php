<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Enum\ShiftAudience;
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
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly PlannerDraftStore $drafts,
        private readonly PlannerPresenter $presenter,
        private readonly DisplaySettings $display,
        private readonly EventConfigStore $config,
        private readonly ShiftTaskRepository $tasks,
        private readonly \App\Service\Shift\ShiftTaskAccess $taskAccess,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_manage_shifts_planner', methods: ['GET'])]
    public function index(Request $request, VolunteerTypeRepository $types, LocationRepository $locations): Response
    {
        $planningDepartments = array_values(array_filter(
            $this->departments->findAllOrdered(),
            static fn (Department $d) => !$d->isOrganizational(),
        ));
        if ($planningDepartments === []) {
            return $this->render('planner/empty.html.twig');
        }

        $department = ($id = $request->query->get('department'))
            ? ($this->departments->findOneByUuid((string) $id) ?? $planningDepartments[0])
            : $planningDepartments[0];
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
            'volunteerTypes' => $types->findAllOrdered(),
            'locations' => $locations->findAllOrdered(),
            'audiences' => ShiftAudience::cases(),
            'timezone' => $tz->getName(),
        ]);
    }

    /**
     * Create a shift task for the department being planned.
     *
     * Shift tasks used to be creatable only from the management screen, so planning a shift for a
     * task that did not exist yet meant leaving the planner and finding an admin. A manager with
     * `shift:manage` on the department owns its tasks, so they can add one here. The task belongs to
     * that department — the global pool stays an admin's to change.
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

        return new JsonResponse(['ok' => true, 'id' => $task->getId(), 'name' => $task->getName()]);
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
        $task = ($tid = (int) ($data['task'] ?? 0)) ? $this->tasks->find($tid) : null;
        $location = ($lid = (int) ($data['location'] ?? 0)) ? $locations->find($lid) : null;

        try {
            $shifts = $this->drafts->createConsolidated(
                $department,
                $intervals,
                $audience,
                $task instanceof ShiftTask ? $task : null,
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
        $task = ($tid = $request->request->getInt('task')) ? $this->tasks->find($tid) : null;
        $location = ($lid = $request->request->getInt('location')) ? $locations->find($lid) : null;

        try {
            $shift = $this->drafts->createDraft(
                $department,
                $start,
                $end,
                $audience,
                $task instanceof ShiftTask ? $task : null,
                $location instanceof Location ? $location : null,
                $this->user(),
                trim((string) $request->request->get('title')) ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $shift->setRequireCheckin($request->request->getBoolean('require_checkin'));
        $this->applyNeededTypes($shift, $request, $types);

        return new JsonResponse(['ok' => true, 'id' => $shift->getId()]);
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
            'volunteerTypes' => $types->findAllOrdered(),
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
        if ($request->request->has('audience')) {
            $fields['audience'] = ShiftAudience::tryFrom((string) $request->request->get('audience')) ?? $shift->getAudience();
        }
        if ($request->request->has('task')) {
            $fields['task'] = ($tid = $request->request->getInt('task')) ? $this->tasks->find($tid) : null;
        }
        if ($request->request->has('location')) {
            $fields['location'] = ($lid = $request->request->getInt('location')) ? $locations->find($lid) : null;
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
            return new JsonResponse(['ok' => false, 'errors' => $result->errors], 422);
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

        /** @var int[] $ids */
        $ids = array_map('intval', (array) $request->request->all('ids'));
        $duration = $request->request->getInt('duration_minutes');
        $needType = ($ntid = $request->request->getInt('needed_type')) ? $types->find($ntid) : null;
        $needCount = $request->request->getInt('needed_count');

        $applied = 0;
        foreach ($ids as $id) {
            $shift = $this->shifts->find($id);
            if ($shift === null || !$this->isGranted('shift:manage', $shift->getDepartment())) {
                continue; // silently skip out-of-scope shifts
            }
            if ($duration >= PlannerDraftStore::MIN_DURATION_MINUTES) {
                $this->drafts->setDuration($shift, $duration, $this->user());
            }
            if ($needType instanceof VolunteerType) {
                $this->drafts->setNeededVolunteerType($shift, $needType, $needCount);
            }
            ++$applied;
        }

        return new JsonResponse(['ok' => true, 'applied' => $applied]);
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
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
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
     * Apply the `needed[typeId] = count` staffing requirements from a create/edit
     * form to the shift.
     */
    private function applyNeededTypes(Shift $shift, Request $request, VolunteerTypeRepository $types): void
    {
        /** @var array<int|string, mixed> $needed */
        $needed = (array) $request->request->all('needed');
        foreach ($needed as $typeId => $count) {
            $type = $types->find((int) $typeId);
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
