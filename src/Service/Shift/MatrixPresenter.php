<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Shift;
use App\Repository\PositionGroupRepository;

/**
 * Builds the Advanced Matrix Planner view model: shifts/work blocks as
 * rows, the department's Position Groups and their Named Positions as grouped
 * columns, and structured cells. All labels are department data - nothing about a
 * specific department (e.g. stage roles) is hard-coded.
 *
 * Cell state maps the stage reference values structurally:
 *   - 'filled'       → occupied by user(s);
 *   - 'open'         → required and unfilled (the `?`);
 *   - 'not_required' → position not required for this shift (the `-`);
 *   - 'empty'        → the position is not enabled on this shift.
 */
final class MatrixPresenter
{
    public function __construct(private readonly PositionGroupRepository $groups)
    {
    }

    /**
     * Cells carry the UUIDs the editing endpoints resolve by, plus each assignment's identity, so a
     * cell can be acted on directly. Internal ids stay internal.
     *
     * @param Shift[] $shifts
     *
     * @return array{
     *     groups: list<array{id: int, name: string, positions: list<array{id: int, name: string}>}>,
     *     columns: list<array{positionId: int, positionUuid: string, name: string, group: string}>,
     *     rows: list<array{shift: Shift, overnight: bool, cells: array<int, array{
     *         state: string,
     *         occupants: list<string>,
     *         assignments: list<array{uuid: string, name: string}>,
     *         note: ?string,
     *         shiftPositionUuid: ?string,
     *         required: bool,
     *         capacity: int,
     *         full: bool
     *     }>}>
     * }
     */
    public function buildMatrix(Department $department, array $shifts, \DateTimeZone $tz): array
    {
        $groups = [];
        $columns = [];
        foreach ($this->groups->findForDepartment($department) as $group) {
            $positions = [];
            foreach ($group->getPositions() as $position) {
                $positions[] = ['id' => $position->getId(), 'name' => $position->getName()];
                $columns[] = [
                    'positionId' => $position->getId(),
                    'positionUuid' => (string) $position->getUuid(),
                    'name' => $position->getName(),
                    'group' => $group->getName(),
                ];
            }
            $groups[] = ['id' => $group->getId(), 'name' => $group->getName(), 'positions' => $positions];
        }

        $rows = [];
        foreach ($shifts as $shift) {
            $enabled = [];
            foreach ($shift->getShiftPositions() as $shiftPosition) {
                $enabled[$shiftPosition->getNamedPosition()->getId()] = $shiftPosition;
            }

            $cells = [];
            foreach ($columns as $column) {
                $shiftPosition = $enabled[$column['positionId']] ?? null;
                if ($shiftPosition === null) {
                    $cells[$column['positionId']] = [
                        'state' => 'empty',
                        'occupants' => [],
                        'assignments' => [],
                        'note' => null,
                        'shiftPositionUuid' => null,
                        'required' => false,
                        'capacity' => 0,
                        'full' => false,
                    ];
                    continue;
                }
                $occupants = [];
                $assignments = [];
                foreach ($shiftPosition->getAssignments() as $assignment) {
                    $occupants[] = $assignment->getUser()->getName();
                    $assignments[] = ['uuid' => (string) $assignment->getUuid(), 'name' => $assignment->getUser()->getName()];
                }
                $capacity = $shiftPosition->getNamedPosition()->getCapacity();
                $cells[$column['positionId']] = [
                    'state' => $shiftPosition->cellState(),
                    'occupants' => $occupants,
                    'assignments' => $assignments,
                    'note' => $shiftPosition->getNote(),
                    'shiftPositionUuid' => (string) $shiftPosition->getUuid(),
                    'required' => $shiftPosition->isRequired(),
                    'capacity' => $capacity,
                    'full' => \count($assignments) >= $capacity,
                ];
            }

            $localEnd = $shift->getEndsAt()->setTimezone($tz);
            $localStart = $shift->getStartsAt()->setTimezone($tz);
            $rows[] = [
                'shift' => $shift,
                'overnight' => $localEnd->format('Y-m-d') !== $localStart->format('Y-m-d'),
                'cells' => $cells,
            ];
        }

        return ['groups' => $groups, 'columns' => $columns, 'rows' => $rows];
    }
}
