<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Repository\DutyRecordRepository;
use App\Repository\GoodieDistributionRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;
use App\Service\DutyService;
use App\Service\StaffStatsService;
use App\Service\UserSearchResultFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Staff Operational Suite - overview, duty control, live status and personal
 * stats. Gated to staff
 */
#[Route('/staff')]
#[IsGranted('ROLE_STAFF')]
final class StaffController extends AbstractController
{
    public function __construct(
        private readonly DutyService $duty,
        private readonly StaffStatsService $stats,
        private readonly DepartmentRepository $departments,
        private readonly ShiftEntryRepository $entries,
        private readonly GoodieDistributionRepository $distributions,
    ) {
    }

    #[Route('', name: 'app_staff_overview', methods: ['GET'])]
    public function overview(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $nextShift = null;
        foreach ($this->entries->findByUserOrdered($user) as $entry) {
            if (!$entry->getShift()->isPast()) {
                $nextShift = $entry;
                break;
            }
        }

        return $this->render('staff/overview.html.twig', [
            'currentDuty' => $this->duty->getCurrentDuty($user),
            'departments' => $this->departments->findAllOrdered(),
            'nextShift' => $nextShift,
            'stats' => $this->stats->userStats($user),
            'goodies' => $this->distributions->findByUser($user),
        ]);
    }

    #[Route('/duty/start', name: 'app_staff_duty_start', methods: ['POST'])]
    public function startDuty(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('duty-start', (string) $request->request->get('_token'))) {
            if ($this->duty->getCurrentDuty($user) !== null) {
                $this->addFlash('warning', new TranslatableMessage('staff.flash.already_on_duty'));
            } else {
                $department = ($id = $request->request->get('department')) ? $this->departments->findOneByUuid((string) $id) : null;
                $this->duty->startDuty($user, $department);
                $this->addFlash('success', new TranslatableMessage('staff.flash.now_on_duty'));
            }
        }

        return $this->redirectToRoute('app_staff_overview');
    }

    #[Route('/duty/end', name: 'app_staff_duty_end', methods: ['POST'])]
    public function endDuty(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('duty-end', (string) $request->request->get('_token'))) {
            $this->duty->endDuty($user) !== null
                ? $this->addFlash('success', new TranslatableMessage('staff.flash.now_off_duty'))
                : $this->addFlash('warning', new TranslatableMessage('staff.flash.not_on_duty'));
        }

        return $this->redirectToRoute('app_staff_overview');
    }

    #[Route('/live', name: 'app_staff_live', methods: ['GET'])]
    public function live(DutyRecordRepository $duties): Response
    {
        // Group open duties by department; departments with nobody on duty are warnings.
        $byDepartment = [];
        foreach ($duties->findActive() as $record) {
            $key = $record->getDepartment()?->getId() ?? 0;
            $byDepartment[$key]['department'] = $record->getDepartment();
            $byDepartment[$key]['records'][] = $record;
        }

        $understaffed = [];
        foreach ($this->departments->findAllOrdered() as $department) {
            if (!isset($byDepartment[$department->getId()])) {
                $understaffed[] = $department;
            }
        }

        return $this->render('staff/live.html.twig', [
            'byDepartment' => $byDepartment,
            // 'understaffed' => $understaffed,
        ]);
    }

    #[Route('/stats', name: 'app_staff_stats', methods: ['GET'])]
    public function stats(Request $request, UserRepository $users): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        // Admins may view another user's stats via ?user=ID.
        $target = $me;
        if ($this->isGranted('global:admin') && ($id = $request->query->get('user'))) {
            $target = $users->findOneByUuid((string) $id) ?? $me;
        }

        return $this->render('staff/stats.html.twig', [
            'target' => $target,
            'isOther' => $target !== $me,
            'stats' => $this->stats->userStats($target),
            'dutyHistory' => $this->duty->getHistory($target),
            'shiftEntries' => $this->entries->findByUserOrdered($target),
            'canSwitch' => $this->isGranted('global:admin'),
        ]);
    }

    #[Route('/stats/user-search', name: 'app_staff_stats_user_search', methods: ['GET'])]
    #[IsGranted('global:admin')]
    public function statsUserSearch(Request $request, UserRepository $users, UserSearchResultFormatter $formatter): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        return new JsonResponse($formatter->results($q === '' ? [] : $users->searchByName($q)));
    }
}
