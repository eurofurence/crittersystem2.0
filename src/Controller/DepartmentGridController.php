<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\Shift;
use App\Enum\ShiftEntryState;
use App\Repository\DepartmentRepository;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use App\Service\Shift\DepartmentGridService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Department shift management grid: all relevant shifts with fill
 * status, assigned users and open positions, plus a selection-driven side panel
 * showing details, assignments, applications and management actions.
 */
#[Route('/manage-shifts/grid')]
#[IsGranted('shift:manage')]
final class DepartmentGridController extends AbstractController
{
    public function __construct(
        private readonly DepartmentGridService $grid,
        private readonly DepartmentRepository $departments,
        private readonly DisplaySettings $display,
        private readonly EventConfigStore $config,
    ) {
    }

    #[Route('', name: 'app_department_grid', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planning = array_values(array_filter(
            $this->departments->findAllOrdered(),
            static fn (Department $d) => !$d->isOrganizational(),
        ));
        if ($planning === []) {
            return $this->render('planner/empty.html.twig');
        }

        $department = ($id = $request->query->get('department'))
            ? ($this->departments->findOneByUuid((string) $id) ?? $planning[0])
            : $planning[0];
            // dd($department);
        // $this->denyAccessUnlessGranted('shift:manage', $department);

        [$from, $to] = $this->range();

        return $this->render('department_grid/index.html.twig', [
            'department' => $department,
            'departments' => $planning,
            'rows' => $this->grid->grid($department, $from, $to),
        ]);
    }

    #[Route('/shift/{id}/panel', name: 'app_department_grid_panel', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function panel(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift->getDepartment());

        $assignments = [];
        $applications = [];
        foreach ($shift->getEntries() as $entry) {
            if ($entry->getState() === ShiftEntryState::ASSIGNMENT) {
                $assignments[] = $entry;
            } else {
                $applications[] = $entry;
            }
        }

        return $this->render('department_grid/_panel.html.twig', [
            'row' => $this->grid->row($shift),
            'assignments' => $assignments,
            'applications' => $applications,
        ]);
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
