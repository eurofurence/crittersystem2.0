<?php

namespace App\Controller;

use App\Entity\Department;
use App\Pdf\PdfRenderer;
use App\Repository\DepartmentRepository;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use App\Service\Shift\SchedulePdfLayout;
use App\Service\Shift\ScheduleTimelineService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff schedule: a column per person against half-hour rows, and a PDF of the same for posting
 * outside the application. Shifts nobody is assigned to are called out in the grid rather than left
 * to be noticed by their absence.
 */
#[Route('/manage-shifts/schedule')]
#[IsGranted('shift:manage')]
final class ScheduleController extends AbstractController
{
    public function __construct(
        private readonly ScheduleTimelineService $timeline,
        private readonly DepartmentRepository $departments,
        private readonly DisplaySettings $display,
        private readonly EventConfigStore $config,
        private readonly PdfRenderer $pdf,
        private readonly SchedulePdfLayout $layout,
    ) {
    }

    #[Route('', name: 'app_schedule_timeline', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$department, $planning] = $this->resolveDepartment($request);
        if ($department === null) {
            return $this->render('planner/empty.html.twig');
        }

        return $this->render('schedule/timeline.html.twig', array_merge(
            ['department' => $department, 'departments' => $planning],
            $this->timelineData($department),
        ));
    }

    #[Route('.pdf', name: 'app_schedule_timeline_pdf', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        [$department] = $this->resolveDepartment($request);
        if ($department === null) {
            throw $this->createNotFoundException();
        }

        $data = $this->timelineData($department);
        $layout = $this->layout->forUsers($data['users']);

        $html = $this->renderView('schedule/timeline_pdf.html.twig', array_merge(
            ['department' => $department, 'layout' => $layout],
            $data,
        ));
        $pdf = $this->pdf->renderHtml($html, $layout['paper'], $layout['orientation']);

        $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            \sprintf('schedule-%s.pdf', $department->getSlug()),
        ));

        return $response;
    }

    /** @return array{users: list<\App\Entity\User>, rows: list<array<string, mixed>>, timezone: string} */
    private function timelineData(Department $department): array
    {
        $tz = $this->display->timezone();
        [$from, $to] = $this->range();
        $data = $this->timeline->build($department, $from, $to, $tz);

        return ['users' => $data['users'], 'rows' => $data['rows'], 'timezone' => $tz->getName()];
    }

    /** @return array{0: ?Department, 1: list<Department>} */
    private function resolveDepartment(Request $request): array
    {
        // Only the departments this manager may actually open. shift:manage is scoped, so offering
        // the rest puts options in the picker that answer 403 when chosen; an unscoped holder or an
        // admin still sees them all.
        $planning = array_values(array_filter(
            $this->departments->findAllOrdered(),
            fn (Department $d) => !$d->isOrganizational() && $this->isGranted('shift:manage', $d),
        ));
        if ($planning === []) {
            return [null, []];
        }
        $department = ($id = $request->query->get('department'))
            ? ($this->departments->findOneByUuid((string) $id) ?? $planning[0])
            : $planning[0];
        $this->denyAccessUnlessGranted('shift:manage', $department);

        return [$department, $planning];
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
