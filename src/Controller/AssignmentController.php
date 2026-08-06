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
use Symfony\Component\Translation\TranslatableMessage;

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
    public function search(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, UserRepository $users, \App\Service\UserSearchResultFormatter $formatter): JsonResponse
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

        $candidates = array_filter(
            $users->searchByName($q),
            static fn (User $user): bool => !isset($assigned[$user->getId()]),
        );

        return new JsonResponse($formatter->results($candidates));
    }

    #[Route('/assign', name: 'app_shift_staffing_assign', methods: ['POST'])]
    public function assign(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $shift->getDepartment());
        if (!$this->isCsrfTokenValid('staffing_assign'.$shift->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $uuids = array_values(array_unique(array_filter(array_map(strval(...), (array) $request->request->all('users')))));
        if ($uuids === []) {
            $this->addFlash('danger', new TranslatableMessage('assignment.flash.choose_user'));

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        $override = $request->request->getBoolean('override');
        if ($override && !$this->isGranted('assignment:override', $shift->getDepartment())) {
            $this->addFlash('danger', new TranslatableMessage('assignment.flash.no_override'));

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        $actor = $this->getUser();
        $actor = $actor instanceof User ? $actor : null;

        $assigned = $notMember = $needOverride = [];
        foreach ($uuids as $uuid) {
            $user = $users->findOneByUuid($uuid);
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
            $this->addFlash('success', new TranslatableMessage('assignment.flash.assigned', ['%names%' => implode(', ', $assigned)]));
        }
        if ($notMember !== []) {
            $this->addFlash('warning', new TranslatableMessage('assignment.flash.not_member', ['%names%' => implode(', ', $notMember)]));
        }
        if ($needOverride !== []) {
            $this->addFlash('warning', new TranslatableMessage('assignment.flash.need_override', ['%names%' => implode(', ', $needOverride)]));
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
            $this->addFlash('success', new TranslatableMessage('assignment.flash.removed'));
        }

        return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
    }
}
