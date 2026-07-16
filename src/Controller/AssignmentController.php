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
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly EventHoursGuard $hoursGuard,
    ) {
    }

    #[Route('', name: 'app_shift_staffing', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
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

        return $this->render('assignment/staffing.html.twig', [
            'shift' => $shift,
            'rows' => $rows,
        ]);
    }

    /**
     * Type-ahead source for the user picker: partial username matches only, as a small JSON list
     * carrying the flags the widget renders (staff suffix, avatar). Users already on the shift are
     * omitted so they cannot be picked twice.
     */
    #[Route('/search', name: 'app_shift_staffing_search', methods: ['GET'])]
    public function search(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, UserRepository $users): JsonResponse
    {
        $this->denyAccessUnlessGranted('assignment:manage', $shift->getDepartment());

        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return new JsonResponse(['results' => []]);
        }

        $assigned = [];
        foreach ($shift->getEntries() as $entry) {
            $assigned[$entry->getUser()->getId()] = true;
        }

        $results = [];
        foreach ($users->searchByName($q) as $user) {
            if (isset($assigned[$user->getId()])) {
                continue;
            }
            $results[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'staff' => $user->isStaff(),
                'avatar' => $user->getPersonalData()?->getAvatarPath() !== null
                    ? $this->generateUrl('app_media_avatar', ['id' => $user->getUuid()])
                    : null,
            ];
        }

        return new JsonResponse(['results' => $results]);
    }

    #[Route('/assign', name: 'app_shift_staffing_assign', methods: ['POST'])]
    public function assign(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $shift->getDepartment());
        if (!$this->isCsrfTokenValid('staffing_assign'.$shift->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->request->all('users')))));
        if ($ids === []) {
            $this->addFlash('danger', 'Choose at least one user.');

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        $override = $request->request->getBoolean('override');
        if ($override && !$this->isGranted('assignment:override', $shift->getDepartment())) {
            $this->addFlash('danger', 'You are not allowed to override warnings.');

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        $actor = $this->getUser();
        $actor = $actor instanceof User ? $actor : null;

        $assigned = $notMember = $needOverride = [];
        foreach ($ids as $id) {
            $user = $users->find($id);
            if ($user === null) {
                continue;
            }

            // The user must be a confirmed member of a type the shift needs; that type is the assignment.
            $type = null;
            foreach ($shift->getNeededVolunteerTypes() as $need) {
                if ($this->memberships->isConfirmedMember($user, $need->getVolunteerType())) {
                    $type = $need->getVolunteerType();
                    break;
                }
            }
            if ($type === null) {
                $notMember[] = $user->getName();
                continue;
            }

            try {
                $this->assignments->assign($shift, $user, $type, $override, $actor);
                $assigned[] = $user->getName();
            } catch (\RuntimeException) {
                $needOverride[] = $user->getName();
            }
        }

        // Report every outcome so nothing is silently dropped.
        if ($assigned !== []) {
            $this->addFlash('success', \sprintf('Assigned: %s.', implode(', ', $assigned)));
        }
        if ($notMember !== []) {
            $this->addFlash('warning', \sprintf('Not a confirmed member of a volunteer type this shift needs: %s.', implode(', ', $notMember)));
        }
        if ($needOverride !== []) {
            $this->addFlash('warning', \sprintf('Availability or hour warnings block these — tick "override" to assign anyway: %s.', implode(', ', $needOverride)));
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
