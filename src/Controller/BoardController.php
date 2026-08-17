<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\User;
use App\Repository\ShiftRepository;
use App\Service\Board\BoardAccess;
use App\Service\Board\BoardSettings;
use App\Service\Board\BoardShiftRow;
use App\Service\Board\BoardSnapshot;
use App\Service\Board\BoardSnapshotBuilder;
use App\Service\Call\HelpCallService;
use App\Service\DisplaySettings;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The live operations board: one department, one day, on a full-screen standalone surface.
 *
 * `board:view` is department-scoped, and PrivilegeVoter applies that scope only when it is handed the
 * resource - so the class-level attribute means no more than "may reach this module", and every
 * action that names a department re-checks with it. A department the caller may not see answers 404
 * rather than 403, so the board does not confirm which departments exist.
 */
#[Route('/board')]
#[IsGranted('board:view')]
final class BoardController extends AbstractController
{
    /** @var list<string> */
    private const VIEWS = ['overview', 'staff', 'shifts'];

    public function __construct(
        private readonly BoardAccess $access,
        private readonly BoardSettings $settings,
        private readonly BoardSnapshotBuilder $snapshots,
        private readonly HelpCallService $calls,
        private readonly ShiftRepository $shifts,
        private readonly DisplaySettings $display,
    ) {
    }

    #[Route('', name: 'app_board', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $departments = $this->access->departmentsFor($user);
        if ($departments === []) {
            return $this->render('board/no_access.html.twig');
        }

        return $this->redirectToRoute('app_board_department', ['department' => $departments[0]->getUuid()]);
    }

    #[Route('/{department}', name: 'app_board_department', methods: ['GET'], requirements: ['department' => Requirement::UUID])]
    public function department(#[MapEntity(mapping: ['department' => 'uuid'])] Department $department): Response
    {
        $this->denyUnlessVisible($department);

        return $this->redirectToRoute('app_board_show', [
            'department' => $department->getUuid(),
            'date' => $this->defaultDay($department)->format('Y-m-d'),
        ]);
    }

    #[Route('/{department}/{date}', name: 'app_board_show', methods: ['GET'], requirements: [
        'department' => Requirement::UUID,
        'date' => '\d{4}-\d{2}-\d{2}',
    ])]
    public function show(Request $request, #[MapEntity(mapping: ['department' => 'uuid'])] Department $department, string $date): Response
    {
        $this->denyUnlessVisible($department);

        $day = $this->parseDay($date);
        if ($day === null) {
            return $this->redirectToRoute('app_board_department', ['department' => $department->getUuid()]);
        }

        /** @var User $user */
        $user = $this->getUser();

        $view = $this->view($request);
        $board = $this->snapshots->build($department, $day);
        $page = $this->paginate($view, $board, $request);

        return $this->render('board/index.html.twig', [
            'department' => $department,
            'departments' => $this->access->departmentsFor($user),
            'day' => $day,
            'dayParam' => self::param($day),
            'previousDayParam' => self::param($day->modify('-1 day')),
            'nextDayParam' => self::param($day->modify('+1 day')),
            'view' => $view,
            'settings' => $this->settings,
            'board' => $board,
            'page' => $page,
            'callable' => $view === 'shifts' ? $this->callableShifts($user, $page['items']) : [],
        ]);
    }

    /**
     * The live region's contents on their own.
     *
     * Answered as a bare fragment, never as a document: the client refuses anything that looks like
     * a whole page, because a gate that redirected this request would otherwise have its login or
     * onboarding page injected into the middle of the board.
     */
    #[Route('/{department}/{date}/view', name: 'app_board_view', methods: ['GET'], requirements: [
        'department' => Requirement::UUID,
        'date' => '\d{4}-\d{2}-\d{2}',
    ])]
    public function fragment(Request $request, #[MapEntity(mapping: ['department' => 'uuid'])] Department $department, string $date): Response
    {
        $this->denyUnlessVisible($department);

        $day = $this->parseDay($date);
        if ($day === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $user */
        $user = $this->getUser();

        $view = $this->view($request);
        $board = $this->snapshots->build($department, $day);
        $page = $this->paginate($view, $board, $request);

        return $this->render('board/_view.html.twig', [
            'department' => $department,
            'day' => $day,
            'dayParam' => self::param($day),
            'view' => $view,
            'settings' => $this->settings,
            'board' => $board,
            'page' => $page,
            'callable' => $view === 'shifts' ? $this->callableShifts($user, $page['items']) : [],
        ]);
    }

    /**
     * The overflow lists, as Turbo Frame content for the board's modal host.
     *
     * They render outside the live region on purpose: a refresh replaces the view's markup, and a
     * modal inside it would be torn away from whoever opened it mid-read.
     */
    #[Route('/{department}/{date}/active-staff', name: 'app_board_active_staff', methods: ['GET'], requirements: [
        'department' => Requirement::UUID,
        'date' => '\d{4}-\d{2}-\d{2}',
    ])]
    public function activeStaff(#[MapEntity(mapping: ['department' => 'uuid'])] Department $department, string $date): Response
    {
        return $this->modal($department, $date, 'board/_modal_active_staff.html.twig');
    }

    #[Route('/{department}/{date}/attention', name: 'app_board_attention', methods: ['GET'], requirements: [
        'department' => Requirement::UUID,
        'date' => '\d{4}-\d{2}-\d{2}',
    ])]
    public function attention(#[MapEntity(mapping: ['department' => 'uuid'])] Department $department, string $date): Response
    {
        return $this->modal($department, $date, 'board/_modal_attention.html.twig');
    }

    private function modal(Department $department, string $date, string $template): Response
    {
        $this->denyUnlessVisible($department);

        $day = $this->parseDay($date);
        if ($day === null) {
            throw $this->createNotFoundException();
        }

        return $this->render($template, [
            'department' => $department,
            'day' => $day,
            'dayParam' => self::param($day),
            'settings' => $this->settings,
            'board' => $this->snapshots->build($department, $day),
        ]);
    }

    /**
     * 404 rather than 403: a board URL for someone else's department must not confirm that the
     * department exists.
     */
    private function denyUnlessVisible(Department $department): void
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->access->canView($user, $department)) {
            throw $this->createNotFoundException();
        }
    }

    private function view(Request $request): string
    {
        $view = (string) $request->query->get('view', 'overview');

        return \in_array($view, self::VIEWS, true) ? $view : 'overview';
    }

    /**
     * The slice of the list the current view shows.
     *
     * Cast rather than getInt(): a page number that cannot be read is a reason to show the first
     * page, not to answer 400 at a board somebody is watching.
     *
     * @return array{items: list<mixed>, page: int, pages: int, total: int}
     */
    private function paginate(string $view, BoardSnapshot $board, Request $request): array
    {
        $items = match ($view) {
            'staff' => $board->staff,
            'shifts' => $board->shiftRows,
            default => [],
        };
        $perPage = $view === 'staff' ? $this->settings->staffPageSize() : $this->settings->shiftsPageSize();

        $pages = max(1, (int) ceil(\count($items) / $perPage));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);

        return [
            'items' => \array_slice($items, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'pages' => $pages,
            'total' => \count($items),
        ];
    }

    /**
     * Which of the page's shifts this viewer may raise a call for, keyed by public uuid.
     *
     * Decided here rather than in the template so the button is only offered to somebody the
     * existing call action will actually accept: it enforces `call:trigger`, the department scope
     * and its own lead-time window, and a button that fails all three is worse than no button.
     *
     * @param list<BoardShiftRow> $rows
     *
     * @return array<string, bool>
     */
    private function callableShifts(User $user, array $rows): array
    {
        if (!$this->isGranted('call:trigger')) {
            return [];
        }

        $callable = [];
        foreach ($rows as $row) {
            $callable[(string) $row->shift->getUuid()] = $row->helpCall === null
                && $row->status->needsStaffing()
                && $this->isGranted('shift:manage', $row->shift)
                && $this->calls->canTriggerNow($user, $row->shift, $user->isInfoDesk());
        }

        return $callable;
    }

    /**
     * Today when the department has a shift running today, otherwise the earliest later day it has
     * one, otherwise today with an empty board. A manager who opens the board a week before their
     * shifts begin lands on something useful rather than on an empty screen they have to page to.
     */
    private function defaultDay(Department $department): \DateTimeImmutable
    {
        $today = $this->today();
        $first = $this->shifts->firstDayWithShifts($department, $today, $this->display->timezone());

        return $first === null || $first < $today ? $today : $first;
    }

    private function today(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', $this->display->timezone()))->setTime(0, 0);
    }

    private function parseDay(string $date): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->display->timezone());

        return $day === false ? null : $day;
    }

    /**
     * The day as it appears in a board URL.
     *
     * Formatted here rather than in the templates: the board's day is midnight in the *display*
     * timezone, while Twig's `date` filter renders in PHP's default one, so where the two zones
     * disagree a template-formatted link names the day before or after the one on screen and each
     * hop through the pager drifts another day further out.
     *
     * Anything that renders a board URL takes this string; nothing formats the day itself.
     */
    private static function param(\DateTimeImmutable $day): string
    {
        return $day->format('Y-m-d');
    }
}
