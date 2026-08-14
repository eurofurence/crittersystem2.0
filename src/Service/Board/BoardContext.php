<?php

namespace App\Service\Board;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;

/**
 * Everything one board needs, loaded once.
 *
 * The panels and the attention rules all ask overlapping questions about the same day - who is
 * assigned, who is present, how many hours each person has - so the data is gathered in a handful of
 * batched queries and handed round as this, rather than each consumer querying for itself. A board
 * is open all day on several machines and re-reads on every change, so a lazy relation walked per
 * row is the difference between one query and a hundred.
 *
 * Presence is deliberately the union of both sources the application records: an open duty session
 * in this department, and a check-in against one of its shifts. Neither alone answers "who is here".
 */
final class BoardContext
{
    /**
     * @param list<Shift>                                                   $shifts        overlapping the day, soonest first
     * @param array<int, array<int, \App\Entity\NeededVolunteerType>>       $needs         shift id => type id => requirement
     * @param array<int, list<ShiftEntry>>                                  $entriesByShift shift id => entries
     * @param array<int, list<ShiftEntry>>                                  $entriesByUser user id => every entry they hold
     * @param array<int, list<PresenceSpan>>                                $spansByUser   user id => merged presence
     * @param array<int, User>                                              $users         everyone the day involves, by id
     * @param array<int, float>                                             $totalHours    user id => credited hours, whole event
     * @param array<int, float>                                             $todayHours    user id => credited hours, this day
     * @param array<int, string>                                            $roles         user id => volunteer type name here
     */
    public function __construct(
        public readonly Department $department,
        public readonly \DateTimeImmutable $day,
        public readonly \DateTimeImmutable $dayStart,
        public readonly \DateTimeImmutable $dayEnd,
        public readonly \DateTimeImmutable $now,
        public readonly array $shifts,
        public readonly array $needs,
        public readonly array $entriesByShift,
        public readonly array $entriesByUser,
        public readonly array $spansByUser,
        public readonly array $users,
        public readonly array $totalHours,
        public readonly array $todayHours,
        public readonly array $roles,
        public readonly BoardSettings $settings,
    ) {
    }

    /** Total headcount the shift asks for, across every volunteer type it requests. */
    public function neededFor(Shift $shift): int
    {
        $needed = 0;
        foreach ($this->needs[$shift->getId()] ?? [] as $need) {
            $needed += $need->getCount();
        }

        return $needed;
    }

    /** @return list<ShiftEntry> */
    public function entriesFor(Shift $shift): array
    {
        return $this->entriesByShift[$shift->getId()] ?? [];
    }

    public function assignedFor(Shift $shift): int
    {
        return \count($this->entriesFor($shift));
    }

    public function isRunning(Shift $shift): bool
    {
        return $shift->getStartsAt() <= $this->now && $shift->getEndsAt() > $this->now;
    }

    /** How many of the shift's assignees are actually present at this instant. */
    public function presentOn(Shift $shift): int
    {
        $present = 0;
        foreach ($this->entriesFor($shift) as $entry) {
            if ($this->isPresent($entry->getUser())) {
                ++$present;
            }
        }

        return $present;
    }

    public function isPresent(User $user): bool
    {
        foreach ($this->spansByUser[$user->getId()] ?? [] as $span) {
            if ($span->coversInstant($this->now)) {
                return true;
            }
        }

        return false;
    }

    /** The stretch the volunteer is in right now, if any. Drives both "since" and the overwork rule. */
    public function openSpanFor(User $user): ?PresenceSpan
    {
        foreach ($this->spansByUser[$user->getId()] ?? [] as $span) {
            if ($span->coversInstant($this->now)) {
                return $span;
            }
        }

        return null;
    }

    /** @return list<PresenceSpan> */
    public function spansFor(User $user): array
    {
        return $this->spansByUser[$user->getId()] ?? [];
    }

    public function totalHoursFor(User $user): float
    {
        return $this->totalHours[$user->getId()] ?? 0.0;
    }

    public function todayHoursFor(User $user): float
    {
        return $this->todayHours[$user->getId()] ?? 0.0;
    }

    public function roleFor(User $user): ?string
    {
        return $this->roles[$user->getId()] ?? null;
    }

    /** @return list<User> everyone present at this instant */
    public function activeUsers(): array
    {
        $active = [];
        foreach ($this->users as $user) {
            if ($this->isPresent($user)) {
                $active[] = $user;
            }
        }

        return $active;
    }
}
