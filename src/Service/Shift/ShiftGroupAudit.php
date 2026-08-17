<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\ShiftGroup;
use App\Repository\ShiftEntryRepository;

/**
 * Health checks a manager should see on a shift group before volunteers meet it.
 *
 * None of these refuse a save. They describe consequences a manager is unlikely to have intended,
 * and every one of them has a legitimate exception - a briefing that runs inside the first minutes
 * of a long shift genuinely overlaps it.
 */
final class ShiftGroupAudit
{
    public function __construct(
        private readonly ShiftGroupResolver $groups,
        private readonly ShiftEntryRepository $entries,
    ) {
    }

    /** @return list<Shift> members in start order */
    public function membersOf(ShiftGroup $group): array
    {
        return $this->groups->membersOf($group);
    }

    /**
     * Mixed state and mixed audience are warned about because a member nobody can see makes the
     * whole group inapplicable: a volunteer is never signed up for a shift they cannot see.
     *
     * @return list<array{key: string, params: array<string, string|int>}> translation keys with
     *                                                                    parameters, so the manage
     *                                                                    screen stays translatable
     */
    public function warningsFor(ShiftGroup $group): array
    {
        $members = $this->membersOf($group);
        $warnings = [];

        if (\count($members) < 2) {
            $warnings[] = ['key' => 'manage.shift_group.warning.too_few', 'params' => []];

            return $warnings;
        }

        $states = [];
        $audiences = [];
        foreach ($members as $member) {
            $states[$member->getState()->value] = true;
            $audiences[$member->getAudience()->value] = true;
        }
        if (\count($states) > 1) {
            $warnings[] = ['key' => 'manage.shift_group.warning.mixed_state', 'params' => []];
        }
        if (\count($audiences) > 1) {
            $warnings[] = ['key' => 'manage.shift_group.warning.mixed_audience', 'params' => []];
        }

        foreach ($this->overlappingPairs($members) as [$a, $b]) {
            $warnings[] = ['key' => 'manage.shift_group.warning.overlap', 'params' => ['%a%' => $a->getTitle(), '%b%' => $b->getTitle()]];
        }

        $partial = $this->partiallyAssignedCount($members);
        if ($partial > 0) {
            $warnings[] = ['key' => 'manage.shift_group.warning.partial', 'params' => ['%count%' => $partial]];
        }

        return $warnings;
    }

    /**
     * How many volunteers would be left on a partial commitment if this shift joined the group:
     * they hold an entry on at least one existing member but not on the newcomer.
     */
    public function volunteersLeftPartial(ShiftGroup $group, Shift $incoming): int
    {
        $count = 0;
        $seen = [];

        foreach ($this->membersOf($group) as $member) {
            foreach ($member->getEntries() as $entry) {
                $userId = $entry->getUser()->getId();
                if (isset($seen[$userId])) {
                    continue;
                }
                $seen[$userId] = true;
                if ($this->entries->findOneByShiftAndUser($incoming, $entry->getUser()) === null) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * Volunteers holding an entry on some members but not all of them. Managers see this on the
     * staffing screen too, so a broken group cannot quietly become invisible history.
     *
     * @param list<Shift> $members
     */
    public function partiallyAssignedCount(array $members): int
    {
        $held = [];
        foreach ($members as $member) {
            foreach ($member->getEntries() as $entry) {
                $held[$entry->getUser()->getId()] = ($held[$entry->getUser()->getId()] ?? 0) + 1;
            }
        }

        $total = \count($members);

        return \count(array_filter($held, static fn (int $n): bool => $n < $total));
    }

    /**
     * @param list<Shift> $members
     *
     * @return list<array{0: Shift, 1: Shift}>
     */
    private function overlappingPairs(array $members): array
    {
        $pairs = [];
        foreach ($members as $i => $a) {
            foreach (\array_slice($members, $i + 1) as $b) {
                if ($a->getStartsAt() < $b->getEndsAt() && $a->getEndsAt() > $b->getStartsAt()) {
                    $pairs[] = [$a, $b];
                }
            }
        }

        return $pairs;
    }
}
