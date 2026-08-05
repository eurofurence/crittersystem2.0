<?php

namespace App\Service\Shift;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Department;
use App\Entity\NamedPosition;
use App\Entity\PositionGroup;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftPosition;
use App\Entity\ShiftPositionAssignment;
use App\Exception\CapacityConflictException;
use App\Mercure\ShiftSignal;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\NamedPositionRepository;
use App\Repository\PositionGroupRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftPositionAssignmentRepository;
use App\Repository\ShiftPositionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages the Advanced Matrix Planner's structure and occupancy:
 * department Position Groups and Named Positions, per-shift enablement, and
 * assigning users to positions. Enforces that one user holds a single
 * {@see ShiftEntry} per shift while occupying several positions, and
 * respects position capacity. Position changes are audited.
 */
final class PositionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly PositionGroupRepository $groups,
        private readonly NamedPositionRepository $positions,
        private readonly ShiftPositionRepository $shiftPositions,
        private readonly ShiftPositionAssignmentRepository $assignments,
        private readonly ShiftEntryRepository $entries,
        private readonly ShiftConcurrency $concurrency,
        private readonly \App\Repository\UserVolunteerTypeRepository $memberships,
        private readonly ShiftSignal $live,
    ) {
    }

    public function createGroup(Department $department, string $name): PositionGroup
    {
        $group = new PositionGroup($department, $name);
        $group->setDisplayOrder($this->groups->nextDisplayOrder($department));
        $this->em->persist($group);
        $this->em->flush();

        $this->live->departmentChanged($department);

        return $group;
    }

    public function createPosition(PositionGroup $group, string $name, int $capacity = 1): NamedPosition
    {
        $position = new NamedPosition($group, $name);
        $position->setCapacity(max(1, $capacity));
        $position->setDisplayOrder($this->positions->nextDisplayOrder($group));
        $this->em->persist($position);
        $this->em->flush();

        $this->live->departmentChanged($group->getDepartment());

        return $position;
    }

    /**
     * Enable a Named Position on a shift, or return the existing enablement.
     * Idempotent - a shift enables each position at most once.
     */
    public function enablePosition(Shift $shift, NamedPosition $position, bool $required = true): ShiftPosition
    {
        $existing = $this->shiftPositions->findOneByShiftAndPosition($shift, $position);
        if ($existing !== null) {
            return $existing;
        }

        $shiftPosition = new ShiftPosition($shift, $position);
        $shiftPosition->setRequired($required);
        $shift->addShiftPosition($shiftPosition);
        $this->em->persist($shiftPosition);
        $this->em->flush();

        $this->live->staffingChanged($shift);

        return $shiftPosition;
    }

    /**
     * Remove a position from a shift. Removing a position that still
     * holds assignments requires explicit resolution: pass $force to confirm,
     * otherwise this refuses.
     *
     * @throws \RuntimeException when the position has assignments and !$force
     */
    public function disablePosition(ShiftPosition $shiftPosition, bool $force = false): void
    {
        if (!$force && $shiftPosition->getAssignments()->count() > 0) {
            throw new \RuntimeException('This position has assignments. Resolve them before removing it.');
        }
        $shift = $shiftPosition->getShift();
        $shift->removeShiftPosition($shiftPosition);
        $this->em->remove($shiftPosition);
        $this->em->flush();

        $this->live->staffingChanged($shift);
    }

    public function setRequired(ShiftPosition $shiftPosition, bool $required): void
    {
        $shiftPosition->setRequired($required);
        $this->em->flush();

        $this->live->staffingChanged($shiftPosition->getShift());
    }

    public function setNote(ShiftPosition $shiftPosition, ?string $note): void
    {
        $shiftPosition->setNote($note !== null && trim($note) !== '' ? $note : null);
        $this->em->flush();

        $this->live->staffingChanged($shiftPosition->getShift());
    }

    /**
     * Reorder Named Positions within a group by the given id order.
     *
     * @param int[] $orderedIds
     */
    public function reorderPositions(PositionGroup $group, array $orderedIds): void
    {
        $byId = [];
        foreach ($group->getPositions() as $position) {
            $byId[$position->getId()] = $position;
        }
        $order = 1;
        foreach ($orderedIds as $id) {
            if (isset($byId[(int) $id])) {
                $byId[(int) $id]->setDisplayOrder($order++);
            }
        }
        $this->em->flush();

        $this->live->departmentChanged($group->getDepartment());
    }

    /**
     * Reorder a department's Position Groups by the given id order.
     *
     * @param int[] $orderedIds
     */
    public function reorderGroups(Department $department, array $orderedIds): void
    {
        $byId = [];
        foreach ($this->groups->findForDepartment($department) as $group) {
            $byId[$group->getId()] = $group;
        }
        $order = 1;
        foreach ($orderedIds as $id) {
            if (isset($byId[(int) $id])) {
                $byId[(int) $id]->setDisplayOrder($order++);
            }
        }
        $this->em->flush();

        $this->live->departmentChanged($department);
    }

    /**
     * Copy the position structure (which positions are enabled, their
     * required flag and notes) from one shift to another. Assignments
     * are not copied - they are user-specific. Existing positions on the target
     * are updated in place; missing ones are added.
     *
     * @return ShiftPosition[] the target's positions after the copy
     */
    public function copyStructure(Shift $from, Shift $to): array
    {
        foreach ($from->getShiftPositions() as $source) {
            $target = $this->enablePosition($to, $source->getNamedPosition(), $source->isRequired());
            $target->setRequired($source->isRequired());
            $target->setNote($source->getNote());
        }
        $this->em->flush();

        $this->live->staffingChanged($to);

        return $to->getShiftPositions()->toArray();
    }

    /**
     * Attach a Named Position to a user's shift entry. The caller
     * guarantees the entry belongs to this shift. Capacity is enforced and a
     * user never occupies the same position twice. Returns the existing
     * assignment when the pairing already exists.
     *
     * @throws \RuntimeException when the position is full
     */
    public function assign(ShiftPosition $shiftPosition, ShiftEntry $entry): ShiftPositionAssignment
    {
        if ($shiftPosition->getShift() !== $entry->getShift()) {
            throw new \RuntimeException('The shift entry does not belong to this position\'s shift.');
        }

        foreach ($shiftPosition->getAssignments() as $existing) {
            if ($existing->getShiftEntry() === $entry) {
                return $existing;
            }
        }

        // Serialize against a concurrent claim of the same exclusive position:
        // lock the shift, re-check capacity, and let the unique
        // (entry,position) constraint backstop a duplicate.
        try {
            $assignment = $this->concurrency->transactional(function () use ($shiftPosition, $entry): ShiftPositionAssignment {
                $this->concurrency->lockForUpdate($shiftPosition->getShift());

                if ($this->assignments->countForPosition($shiftPosition) >= $shiftPosition->getCapacity()) {
                    throw new CapacityConflictException('This position is already fully occupied.');
                }

                $assignment = new ShiftPositionAssignment($entry, $shiftPosition);
                $this->em->persist($assignment);
                $this->em->flush();

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CapacityConflictException('This position is already fully occupied.');
        }

        $this->live->staffingChanged($shiftPosition->getShift(), $entry->getUser());

        $this->audit->log(AuditEvents::SHIFT, AuditEvents::POSITION_ASSIGN, [
            'resourceType' => 'shift_position',
            'resourceId' => (string) $shiftPosition->getId(),
            'resourceOwnerId' => $entry->getUser()->getId(),
            'details' => [
                'position' => $shiftPosition->getNamedPosition()->getName(),
                'user' => $entry->getUser()->getUserIdentifier(),
            ],
        ]);

        return $assignment;
    }

    /**
     * Assign a user to a position, creating the user's single shift entry first if needed (one entry
     * per user/shift).
     *
     * A new entry has to record a Volunteer Type. Resolution order:
     *   1. an explicit fallback from the caller;
     *   2. a Volunteer Type the position requires (a manager placing somebody into a role the
     *      position defines records the entry under that role);
     *   3. a type the shift needs and the user is a confirmed member of;
     *   4. any type the user is a confirmed member of.
     *
     * Steps 3 and 4 exist because a manager staffing a spot from the planner normally picks somebody
     * who is not yet on the shift, and most positions define no required type - without them the
     * grid cannot staff anyone at all.
     *
     * @throws \RuntimeException when no Volunteer Type can be resolved
     */
    public function assignUser(ShiftPosition $shiftPosition, User $user, ?VolunteerType $fallbackType = null): ShiftPositionAssignment
    {
        $shift = $shiftPosition->getShift();
        $entry = $this->entries->findOneByShiftAndUser($shift, $user);
        if ($entry === null) {
            $type = $fallbackType ?? $this->resolveEntryType($shiftPosition, $user);
            if (!$type instanceof VolunteerType) {
                throw new \RuntimeException('No volunteer type is available to record this assignment. Give the position a required volunteer type, or confirm the volunteer in one.');
            }
            $entry = new ShiftEntry($shift, $type, $user);
            $this->em->persist($entry);
            $this->em->flush();
        }

        return $this->assign($shiftPosition, $entry);
    }

    /**
     * The Volunteer Type a new shift entry is recorded under; null when none can be resolved.
     *
     * Public because the manager assignment path resolves the role before creating the entry, so
     * that a grouped shift records the same role on every member.
     */
    public function resolveEntryType(ShiftPosition $shiftPosition, User $user): ?VolunteerType
    {
        $required = $shiftPosition->getNamedPosition()->getRequiredVolunteerTypes()->first();
        if ($required instanceof VolunteerType) {
            return $required;
        }

        $confirmed = [];
        foreach ($this->memberships->findByUser($user) as $membership) {
            if ($membership->getConfirmedBy() !== null) {
                $confirmed[$membership->getVolunteerType()->getId()] = $membership->getVolunteerType();
            }
        }
        if ($confirmed === []) {
            return null;
        }

        foreach ($shiftPosition->getShift()->getNeededVolunteerTypes() as $needed) {
            $type = $needed->getVolunteerType();
            if (isset($confirmed[$type->getId()])) {
                return $confirmed[$type->getId()];
            }
        }

        return reset($confirmed);
    }

    /** Detach a position from an entry; audited. */
    public function unassign(ShiftPositionAssignment $assignment): void
    {
        $position = $assignment->getShiftPosition();
        $user = $assignment->getUser();

        $assignment->getShiftEntry()->removePositionAssignment($assignment);
        $position->removeAssignment($assignment);
        $this->em->remove($assignment);
        $this->em->flush();

        $this->live->staffingChanged($position->getShift(), $user);

        $this->audit->log(AuditEvents::SHIFT, AuditEvents::POSITION_UNASSIGN, [
            'resourceType' => 'shift_position',
            'resourceId' => (string) $position->getId(),
            'resourceOwnerId' => $user->getId(),
            'details' => [
                'position' => $position->getNamedPosition()->getName(),
                'user' => $user->getUserIdentifier(),
            ],
        ]);
    }
}
