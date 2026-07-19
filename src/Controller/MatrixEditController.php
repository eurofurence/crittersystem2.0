<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\NamedPosition;
use App\Entity\PositionGroup;
use App\Entity\Shift;
use App\Entity\ShiftPosition;
use App\Entity\ShiftPositionAssignment;
use App\Repository\DepartmentRepository;
use App\Repository\NamedPositionRepository;
use App\Repository\ShiftRepository;
use App\Repository\UserRepository;
use App\Service\Shift\PositionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Advanced Matrix Planner structure editing: create/reorder Position
 * Groups and Named Positions, enable/disable positions per shift, mark
 * required/open, add notes, assign/unassign users, and copy structure between
 * shifts. All mutations are department-scoped and CSRF-guarded.
 */
#[Route('/manage-shifts/matrix')]
#[IsGranted('shift:manage')]
final class MatrixEditController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly PositionService $positions,
    ) {
    }

    #[Route('/group', name: 'app_matrix_group_create', methods: ['POST'])]
    public function createGroup(Request $request): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            return $this->fail('Unknown department.');
        }
        $this->guard($department, $request);
        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            return $this->fail('A group needs a name.');
        }
        $group = $this->positions->createGroup($department, $name);

        return new JsonResponse(['ok' => true, 'id' => $group->getId()]);
    }

    #[Route('/position', name: 'app_matrix_position_create', methods: ['POST'])]
    public function createPosition(Request $request, \App\Repository\PositionGroupRepository $groups): Response
    {
        $group = $groups->find($request->request->getInt('group'));
        if ($group === null) {
            return $this->fail('Unknown position group.');
        }
        $this->guard($group->getDepartment(), $request);
        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            return $this->fail('A position needs a name.');
        }
        $position = $this->positions->createPosition($group, $name, max(1, $request->request->getInt('capacity', 1)));

        return new JsonResponse(['ok' => true, 'id' => $position->getId()]);
    }

    #[Route('/positions/reorder', name: 'app_matrix_positions_reorder', methods: ['POST'])]
    public function reorderPositions(Request $request, \App\Repository\PositionGroupRepository $groups): Response
    {
        $group = $groups->find($request->request->getInt('group'));
        if ($group === null) {
            return $this->fail('Unknown position group.');
        }
        $this->guard($group->getDepartment(), $request);
        $this->positions->reorderPositions($group, array_map('intval', (array) $request->request->all('ids')));

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/shift/{id}/position/{positionId}/enable', name: 'app_matrix_position_enable', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'positionId' => Requirement::UUID])]
    public function enable(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, string $positionId, NamedPositionRepository $positions): Response
    {
        $this->guard($shift->getDepartment(), $request);
        $position = $positions->findOneBy(['uuid' => $positionId]);
        if ($position === null) {
            return $this->fail('Unknown position.');
        }
        $sp = $this->positions->enablePosition($shift, $position, $request->request->getBoolean('required', true));

        return new JsonResponse(['ok' => true, 'shiftPositionId' => $sp->getId()]);
    }

    #[Route('/shift-position/{id}/required', name: 'app_matrix_position_required', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function toggleRequired(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftPosition $shiftPosition): Response
    {
        $this->guard($shiftPosition->getShift()->getDepartment(), $request);
        $this->positions->setRequired($shiftPosition, $request->request->getBoolean('required'));

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/shift-position/{id}/note', name: 'app_matrix_position_note', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function note(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftPosition $shiftPosition): Response
    {
        $this->guard($shiftPosition->getShift()->getDepartment(), $request);
        $this->positions->setNote($shiftPosition, (string) $request->request->get('note'));

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/shift-position/{id}/disable', name: 'app_matrix_position_disable', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function disable(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftPosition $shiftPosition): Response
    {
        $this->guard($shiftPosition->getShift()->getDepartment(), $request);
        try {
            $this->positions->disablePosition($shiftPosition, $request->request->getBoolean('force'));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Type-ahead source for the cell editor's volunteer picker: any user matching the query, not just
     * the department's members - a manager staffing the grid routinely places someone from outside the
     * department, and {@see PositionService::assignUser()} resolves their volunteer type on assign.
     * Assignment itself stays gated by `shift:assign` on the department.
     */
    #[Route('/users', name: 'app_matrix_user_search', methods: ['GET'])]
    #[IsGranted('shift:assign')]
    public function searchUsers(Request $request, UserRepository $users): JsonResponse
    {
        $department = $this->departments->findOneByUuid((string) $request->query->get('department'));
        if ($department === null) {
            return new JsonResponse(['results' => []]);
        }
        $this->denyAccessUnlessGranted('shift:assign', $department);

        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return new JsonResponse(['results' => []]);
        }

        $results = [];
        foreach ($users->searchByName($q) as $user) {
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

    #[Route('/shift-position/{id}/assign', name: 'app_matrix_position_assign', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('shift:assign')]
    public function assign(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftPosition $shiftPosition, UserRepository $users): Response
    {
        $this->guard($shiftPosition->getShift()->getDepartment(), $request);
        $user = $users->find($request->request->getInt('user'));
        if ($user === null) {
            return $this->fail('Unknown user.');
        }
        try {
            $this->positions->assignUser($shiftPosition, $user);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/assignment/{id}/unassign', name: 'app_matrix_position_unassign', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('shift:assign')]
    public function unassign(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftPositionAssignment $assignment): Response
    {
        $this->guard($assignment->getShiftPosition()->getShift()->getDepartment(), $request);
        $this->positions->unassign($assignment);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/copy', name: 'app_matrix_copy_structure', methods: ['POST'])]
    public function copy(Request $request): Response
    {
        $from = $this->shifts->find($request->request->getInt('from'));
        if ($from === null) {
            return $this->fail('Unknown source shift.');
        }
        $this->guard($from->getDepartment(), $request);

        $copied = 0;
        foreach (array_map('intval', (array) $request->request->all('to')) as $toId) {
            $to = $this->shifts->find($toId);
            if ($to !== null && $to !== $from && $this->isGranted('shift:manage', $to->getDepartment())) {
                $this->positions->copyStructure($from, $to);
                ++$copied;
            }
        }

        return new JsonResponse(['ok' => true, 'copied' => $copied]);
    }

    private function guard(?Department $department, Request $request): void
    {
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('matrix_edit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }

    private function fail(string $message, int $status = 422): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $status);
    }
}
