<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftGroup;
use App\Entity\User;
use App\Repository\ShiftEntryRepository;

/**
 * Reads shift-group membership. The one place group membership is interpreted, so the sign-up
 * service, the manager assignment paths, the modal and the bot cannot disagree about what a group
 * contains.
 *
 * A shift with no group, or whose group holds only itself, is reported as a group of one. Every
 * caller can then treat the grouped and ungrouped cases with the same code and none of them needs a
 * null check.
 */
final class ShiftGroupResolver
{
    public function __construct(
        private readonly ShiftVisibilityResolver $visibility,
        private readonly ShiftEntryRepository $entries,
    ) {
    }

    /**
     * The shift and its siblings in start order.
     *
     * @return list<Shift>
     */
    public function membersFor(Shift $shift): array
    {
        if (!$shift->isGrouped()) {
            return [$shift];
        }

        return $this->membersOf($shift->getShiftGroup());
    }

    /** @return list<Shift> */
    public function membersOf(ShiftGroup $group): array
    {
        $members = $group->getShifts()->toArray();
        usort($members, static fn (Shift $a, Shift $b): int => $a->getStartsAt() <=> $b->getStartsAt() ?: $a->getId() <=> $b->getId());

        return array_values($members);
    }

    /**
     * The members in a stable lock order.
     *
     * Two volunteers applying to the same group from opposite ends would deadlock if each locked
     * the members in the order it happened to see them, so every writer locks by ascending primary
     * key.
     *
     * @return list<Shift>
     */
    public function membersForUpdate(Shift $shift): array
    {
        $members = $this->membersFor($shift);
        usort($members, static fn (Shift $a, Shift $b): int => $a->getId() <=> $b->getId());

        return $members;
    }

    /**
     * The siblings of a shift, excluding the shift itself.
     *
     * @return list<Shift>
     */
    public function siblingsOf(Shift $shift): array
    {
        return array_values(array_filter($this->membersFor($shift), static fn (Shift $m): bool => $m !== $shift));
    }

    /**
     * Whether every member of this shift's group is visible to the viewer.
     *
     * A group holding one shift the viewer may not see is not applicable at all: signing them up
     * would put them on a shift they cannot see, and naming the shift in the refusal would confirm
     * it exists. Callers refuse with a neutral message and never mention the hidden member.
     */
    public function isFullyVisibleTo(Shift $shift, ?User $user): bool
    {
        foreach ($this->membersFor($shift) as $member) {
            if (!$this->visibility->isVisibleTo($member, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The user's entries across the whole group, in member order.
     *
     * @return list<ShiftEntry>
     */
    public function entriesFor(Shift $shift, User $user): array
    {
        $found = [];
        foreach ($this->membersFor($shift) as $member) {
            $entry = $this->entries->findOneByShiftAndUser($member, $user);
            if ($entry !== null) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /** Hours the whole group adds, used by the recommended-hours guard and the confirmation modal. */
    public function totalDurationHours(Shift $shift): float
    {
        $hours = 0.0;
        foreach ($this->membersFor($shift) as $member) {
            $hours += $member->getDurationHours();
        }

        return $hours;
    }
}
