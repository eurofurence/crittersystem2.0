<?php

namespace App\Controller;

use App\Entity\Department;
use App\Pdf\PdfRenderer;
use App\Repository\DepartmentRepository;
use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use App\Service\Shift\ScheduleTimelineService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff schedule timeline: an interactive users×time view and a
 * dompdf export for posting outside the application. Location labels rotate in
 * the PDF when space is tight.
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

        $html = $this->renderView('schedule/timeline_pdf.html.twig', array_merge(
            ['department' => $department],
            $this->timelineData($department),
        ));
        $pdf = $this->pdf->renderHtml($html, 'A4', 'landscape');

        $response = new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            \sprintf('schedule-%s.pdf', $department->getSlug()),
        ));

        return $response;
    }

    /** @return array{days: mixed, rows: mixed, timezone: string} */
    private function timelineData(Department $department): array
    {
        $tz = $this->display->timezone();
        [$from, $to] = $this->range();
        $data = $this->timeline->build(
            $department,
            $from,
            $to,
            $tz,
            $this->config->getDate(EventConfigStore::KEY_EVENT_START),
            $this->config->getDate(EventConfigStore::KEY_EVENT_END),
        );

        return ['days' => $data['days'], 'rows' => $data['rows'], 'timezone' => $tz->getName()];
    }

    /** @return array{0: ?Department, 1: list<Department>} */
    private function resolveDepartment(Request $request): array
    {
        $planning = array_values(array_filter(
            $this->departments->findAllOrdered(),
            static fn (Department $d) => !$d->isOrganizational(),
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
