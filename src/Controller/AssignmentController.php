<?php

namespace App\Controller;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\Assignment\EventHoursGuard;
use App\Service\Assignment\ManualAssignmentService;
use App\Service\DepartmentService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manual assignment of users to a shift: add department members or
 * search other users, assign as a volunteer type, override availability/hour
 * warnings with explicit confirmation, and remove. Overrides are marked and
 * audited by the service.
 */
#[Route('/manage-shifts/shift/{id}/staffing', requirements: ['id' => Requirement::UUID])]
#[IsGranted('assignment:manage')]
final class AssignmentController extends AbstractController
{
    public function __construct(
        private readonly ManualAssignmentService $assignments,
        private readonly DepartmentService $departments,
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly EventHoursGuard $hoursGuard,
    ) {
    }

    #[Route('', name: 'app_shift_staffing', methods: ['GET'])]
    public function index(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $shift->getDepartment());

        $rows = [];
        foreach ($shift->getEntries() as $entry) {
            $rows[] = [
                'entry' => $entry,
                'inspection' => $this->assignments->inspect($shift, $entry->getUser()),
                'overThreshold' => $this->hoursGuard->isOver($entry->getUser()),
            ];
        }

        // Candidates: department members + optional username/nickname search.
        $members = $this->departments->members($shift->getDepartment());
        $candidates = array_merge($members['staff'] ?? [], $members['nonStaff'] ?? [], $members['managers'] ?? [], $members['shiftManagers'] ?? []);
        if ($q = trim((string) $request->query->get('q'))) {
            $candidates = $users->search($q);
        }

        return $this->render('assignment/staffing.html.twig', [
            'shift' => $shift,
            'rows' => $rows,
            'candidates' => $candidates,
            'query' => $q ?? '',
        ]);
    }

    #[Route('/assign', name: 'app_shift_staffing_assign', methods: ['POST'])]
    public function assign(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $shift->getDepartment());
        if (!$this->isCsrfTokenValid('staffing_assign'.$shift->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $users->find($request->request->getInt('user'));
        if ($user === null) {
            $this->addFlash('danger', 'Choose a user.');

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        // The user must be a confirmed member of a type requested by the shift.
        $type = null;
        foreach ($shift->getNeededVolunteerTypes() as $need) {
            if ($this->memberships->isConfirmedMember($user, $need->getVolunteerType())) {
                $type = $need->getVolunteerType();
                break;
            }
        }
        if ($type === null) {
            $this->addFlash('danger', 'The user is not a confirmed member of any volunteer type this shift needs.');

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        $override = $request->request->getBoolean('override');
        if ($override && !$this->isGranted('assignment:override', $shift->getDepartment())) {
            $this->addFlash('danger', 'You are not allowed to override warnings.');

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        try {
            $actor = $this->getUser();
            $this->assignments->assign($shift, $user, $type, $override, $actor instanceof User ? $actor : null);
            $this->addFlash('success', \sprintf('%s assigned.', $user->getName()));
        } catch (\RuntimeException $e) {
            $this->addFlash('warning', $e->getMessage().' Tick "override" to confirm.');
        }

        return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
    }

    #[Route('/remove/{entryId}', name: 'app_shift_staffing_remove', methods: ['POST'], requirements: ['entryId' => Requirement::UUID])]
    public function remove(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, string $entryId): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $shift->getDepartment());
        $entry = $this->entries->findOneBy(['uuid' => $entryId]);
        if ($entry instanceof ShiftEntry && $entry->getShift() === $shift
            && $this->isCsrfTokenValid('staffing_remove'.$entryId, (string) $request->request->get('_token'))) {
            $this->assignments->remove($entry);
            $this->addFlash('success', 'Assignment removed.');
        }

        return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
    }
}
