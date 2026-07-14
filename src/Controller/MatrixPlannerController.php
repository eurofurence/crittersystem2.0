<?php

namespace App\Controller;

use App\Entity\Department;
use App\Pdf\PdfRenderer;
use App\Repository\DepartmentRepository;
use App\Repository\ShiftRepository;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use App\Service\Shift\MatrixPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Advanced Matrix Planner: the same shift/position model as the
 * Standard Planner, shown as shifts-as-rows against configurable Position Group
 * columns. This controller renders the matrix; structure editing lives in
 * {@see MatrixEditController}.
 */
#[Route('/manage-shifts/matrix')]
#[IsGranted('shift:manage')]
final class MatrixPlannerController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly MatrixPresenter $presenter,
        private readonly DisplaySettings $display,
        private readonly EventConfigStore $config,
        private readonly PdfRenderer $pdf,
        private readonly \App\Service\DepartmentService $members,
    ) {
    }

    #[Route('', name: 'app_manage_shifts_matrix', methods: ['GET'])]
    public function index(Request $request): Response
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

        return $this->render('planner/matrix.html.twig', [
            'department' => $department,
            'departments' => $planningDepartments,
            'matrix' => $this->presenter->buildMatrix($department, $shifts, $tz),
            'shifts' => $shifts,
            'timezone' => $tz->getName(),
            'candidates' => $this->candidates($department, $shifts),
        ]);
    }

    /**
     * Volunteers who can be put into a position: the department's members (the source the staffing
     * screen uses), plus anyone already signed up to a shift in view — they are committed to this
     * department's work, so a manager must be able to place them without leaving the grid.
     *
     * Assignment is additionally gated by `shift:assign` on the server.
     *
     * @param Shift[] $shifts
     *
     * @return list<\App\Entity\User>
     */
    private function candidates(Department $department, array $shifts): array
    {
        $members = $this->members->members($department);
        $candidates = array_merge(
            $members['staff'] ?? [],
            $members['nonStaff'] ?? [],
            $members['managers'] ?? [],
            $members['shiftManagers'] ?? [],
        );

        foreach ($shifts as $shift) {
            foreach ($shift->getEntries() as $entry) {
                $candidates[] = $entry->getUser();
            }
        }

        $unique = [];
        foreach ($candidates as $user) {
            $unique[$user->getId()] = $user;
        }
        usort($unique, static fn ($a, $b) => strcasecmp($a->getName(), $b->getName()));

        return array_values($unique);
    }

    #[Route('.pdf', name: 'app_manage_shifts_matrix_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        $department = ($id = $request->query->get('department')) ? $this->departments->findOneByUuid((string) $id) : null;
        if ($department === null) {
            $department = array_values(array_filter(
                $this->departments->findAllOrdered(),
                static fn (Department $d) => !$d->isOrganizational(),
            ))[0] ?? null;
        }
        if ($department === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);

        [$rangeStart, $rangeEnd] = $this->range();
        $tz = $this->display->timezone();
        $shifts = $this->shifts->findForDepartmentBetween($department, $rangeStart, $rangeEnd);

        $html = $this->renderView('planner/matrix_pdf.html.twig', [
            'department' => $department,
            'matrix' => $this->presenter->buildMatrix($department, $shifts, $tz),
        ]);
        $pdf = $this->pdf->renderHtml($html, 'A4', 'landscape');

        $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            \sprintf('matrix-%s.pdf', $department->getSlug()),
        ));

        return $response;
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
