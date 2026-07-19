<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Repository\DepartmentRepository;
use App\Repository\LocationRepository;
use App\Repository\ShiftTaskRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use App\Service\Shift\PlannerPresenter;
use App\Service\Shift\ShiftWizardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The Shift Wizard: generate repeated draft shifts across selected
 * days from a daily time window and slot duration. Created shifts are drafts and
 * are reviewed/published in the planner afterwards.
 */
#[Route('/manage-shifts/wizard')]
#[IsGranted('shift:manage')]
final class ShiftWizardController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly ShiftWizardService $wizard,
        private readonly PlannerPresenter $presenter,
        private readonly DisplaySettings $display,
        private readonly EventConfigStore $config,
        private readonly ShiftTaskRepository $tasks,
        private readonly \App\Service\Shift\ShiftTaskAccess $taskAccess,
    ) {
    }

    #[Route('', name: 'app_manage_shifts_wizard', methods: ['GET', 'POST'])]
    public function wizard(Request $request, VolunteerTypeRepository $types, LocationRepository $locations): Response
    {
        $department = $this->resolveDepartment($request);
        if ($department === null) {
            return $this->redirectToRoute('app_manage_shifts_planner');
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);

        $tz = $this->display->timezone();
        [$rangeStart, $rangeEnd] = $this->range();
        $days = $this->presenter->dayList(
            $rangeStart,
            $rangeEnd,
            $tz,
            $this->config->getDate(EventConfigStore::KEY_EVENT_START),
            $this->config->getDate(EventConfigStore::KEY_EVENT_END),
        );

        if ($request->isMethod('POST')) {
            return $this->generate($request, $department, $types, $locations, $tz);
        }

        return $this->render('planner/wizard.html.twig', [
            'department' => $department,
            'days' => $days,
            'audiences' => ShiftAudience::cases(),
            'shiftTasks' => $this->taskAccess->forDepartment($this->tasks->findAllOrdered(), $department),
            'locations' => $locations->findAllOrdered(),
            'volunteerTypes' => $types->findAllOrdered(),
        ]);
    }

    private function generate(Request $request, Department $department, VolunteerTypeRepository $types, LocationRepository $locations, \DateTimeZone $tz): Response
    {
        if (!$this->isCsrfTokenValid('shift_wizard', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('shift_manager.wizard.flash.invalid_token'));

            return $this->redirectToRoute('app_manage_shifts_wizard', ['department' => $department->getUuid()]);
        }

        $dates = array_values(array_filter(array_map('trim', array_merge(
            (array) $request->request->all('dates'),
            preg_split('/[\s,]+/', (string) $request->request->get('custom_dates', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ))));

        $audience = ShiftAudience::tryFrom((string) $request->request->get('audience', '')) ?? ShiftAudience::PUBLIC_VOLUNTEER;
        $task = ($tid = $request->request->getInt('task')) ? $this->tasks->find($tid) : null;
        $location = ($lid = $request->request->getInt('location')) ? $locations->find($lid) : null;

        $needed = [];
        foreach ((array) $request->request->all('needed') as $typeId => $count) {
            $type = $types->find((int) $typeId);
            if ($type instanceof VolunteerType && (int) $count > 0) {
                $needed[] = [$type, (int) $count];
            }
        }

        try {
            $created = $this->wizard->generate(
                $department,
                $dates,
                (string) $request->request->get('start_time', '10:00'),
                (string) $request->request->get('end_time', '18:00'),
                $request->request->getInt('slot_minutes', 120),
                $tz,
                $audience,
                $task instanceof ShiftTask ? $task : null,
                $location instanceof Location ? $location : null,
                $needed,
                $this->getUser() instanceof User ? $this->getUser() : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_manage_shifts_wizard', ['department' => $department->getUuid()]);
        }

        $this->addFlash('success', new TranslatableMessage('shift_manager.wizard.flash.created', ['%count%' => \count($created)]));

        return $this->redirectToRoute('app_manage_shifts_planner', ['department' => $department->getUuid()]);
    }

    private function resolveDepartment(Request $request): ?Department
    {
        if ($id = $request->query->get('department') ?: $request->request->get('department')) {
            return $this->departments->findOneByUuid((string) $id);
        }
        foreach ($this->departments->findAllOrdered() as $dept) {
            if (!$dept->isOrganizational()) {
                return $dept;
            }
        }

        return null;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function range(): array
    {
        $start = $this->config->getDate(EventConfigStore::KEY_BUILDUP_START)
            ?? $this->config->getDate(EventConfigStore::KEY_EVENT_START)
            ?? new \DateTimeImmutable('today');
        $end = $this->config->getDate(EventConfigStore::KEY_TEARDOWN_END)
            ?? $this->config->getDate(EventConfigStore::KEY_EVENT_END)
            ?? $start->modify('+3 days');

        return [$start, $end->modify('+1 day')];
    }
}
