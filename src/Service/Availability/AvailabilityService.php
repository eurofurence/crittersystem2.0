<?php

namespace App\Service\Availability;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\AvailabilityRange;
use App\Entity\PlanningAvailability;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Enum\AvailabilityValue;
use App\Enum\ShiftEntryState;
use App\Repository\PlanningAvailabilityRepository;
use App\Repository\ShiftEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages a user's single global Planning Availability. Submitting
 * replaces the declared ranges (the crab.fit grid posts the whole schedule),
 * consolidating touching same-value spans. Confirmed assignments are surfaced as
 * occupied overlays.
 */
final class AvailabilityService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningAvailabilityRepository $availabilities,
        private readonly ShiftEntryRepository $entries,
        private readonly AuditLogger $audit,
    ) {
    }

    public function getOrCreate(User $user): PlanningAvailability
    {
        $availability = $this->availabilities->findOneByUser($user);
        if ($availability === null) {
            $availability = new PlanningAvailability($user);
            $this->em->persist($availability);
        }

        return $availability;
    }

    /**
     * Replace the user's declared availability with the submitted ranges,
     * consolidating touching same-value spans (one global schedule per user).
     *
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, value: AvailabilityValue}> $ranges
     */
    public function submit(User $user, array $ranges, ?string $comment): PlanningAvailability
    {
        $availability = $this->getOrCreate($user);
        $availability->clearRanges();
        $availability->setComment($comment !== null && trim($comment) !== '' ? $comment : null);

        foreach ($this->consolidate($ranges) as [$start, $end, $value]) {
            $range = new AvailabilityRange($availability, $start, $end, $value);
            $this->em->persist($range);
        }
        $availability->touch();
        $this->em->flush();

        $this->audit->log(AuditEvents::SHIFT, AuditEvents::UPDATE, [
            'resourceType' => 'planning_availability',
            'resourceId' => (string) $availability->getId(),
            'resourceOwnerId' => $user->getId(),
            'details' => ['ranges' => $availability->getRanges()->count()],
        ]);

        return $availability;
    }

    /** @return AvailabilityRange[] the user's declared ranges */
    public function rangesForUser(User $user): array
    {
        $availability = $this->availabilities->findOneByUser($user);

        return $availability !== null ? $availability->getRanges()->toArray() : [];
    }

    /**
     * The user's confirmed assignments as occupied overlays for the grid
     * (existing assignments always take precedence).
     *
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, title: string}>
     */
    public function occupiedOverlays(User $user): array
    {
        $overlays = [];
        foreach ($this->entries->findByUserOrdered($user) as $entry) {
            if ($entry->getState() !== ShiftEntryState::ASSIGNMENT) {
                continue;
            }
            $shift = $entry->getShift();
            $overlays[] = [
                'start' => $shift->getStartsAt(),
                'end' => $shift->getEndsAt(),
                'title' => $shift->getTitle(),
            ];
        }

        return $overlays;
    }

    /**
     * Confirmed-assignment intervals that consume availability.
     * Only published/confirmed assignments consume — applications and draft
     * proposals do not. Optionally exclude a shift being (re)planned.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function consumedIntervals(User $user, ?\App\Entity\Shift $exclude = null): array
    {
        $intervals = [];
        foreach ($this->entries->findByUserOrdered($user) as $entry) {
            if ($entry->getState() !== ShiftEntryState::ASSIGNMENT) {
                continue;
            }
            $shift = $entry->getShift();
            if ($exclude !== null && $shift === $exclude) {
                continue;
            }
            $intervals[] = [$shift->getStartsAt(), $shift->getEndsAt()];
        }

        return $intervals;
    }

    /**
     * Effective planning state for a candidate time range: existing
     * assignments always take precedence, so an overlap with a confirmed
     * assignment is `occupied`. Otherwise the value is the least-willing declared
     * value overlapping the range (null when nothing is declared).
     *
     * @return array{occupied: bool, value: ?AvailabilityValue}
     */
    public function planningState(User $user, \DateTimeImmutable $start, \DateTimeImmutable $end, ?\App\Entity\Shift $exclude = null): array
    {
        foreach ($this->consumedIntervals($user, $exclude) as [$cStart, $cEnd]) {
            if ($cStart < $end && $cEnd > $start) {
                return ['occupied' => true, 'value' => null];
            }
        }

        $worst = null;
        foreach ($this->rangesForUser($user) as $range) {
            if ($range->overlaps($start, $end) && ($worst === null || $range->getValue()->rank() > $worst->rank())) {
                $worst = $range->getValue();
            }
        }

        return ['occupied' => false, 'value' => $worst];
    }

    /**
     * Merge touching/overlapping ranges that share a value.
     *
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, value: AvailabilityValue}> $ranges
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: AvailabilityValue}>
     */
    private function consolidate(array $ranges): array
    {
        $byValue = [];
        foreach ($ranges as $range) {
            if ($range['end'] <= $range['start']) {
                continue;
            }
            $byValue[$range['value']->value][] = [$range['start'], $range['end']];
        }

        $merged = [];
        foreach ($byValue as $valueKey => $intervals) {
            $value = AvailabilityValue::from($valueKey);
            usort($intervals, static fn ($a, $b) => $a[0] <=> $b[0]);
            $spans = [];
            foreach ($intervals as [$start, $end]) {
                if ($spans !== [] && $start <= $spans[\count($spans) - 1][1]) {
                    if ($end > $spans[\count($spans) - 1][1]) {
                        $spans[\count($spans) - 1][1] = $end;
                    }
                    continue;
                }
                $spans[] = [$start, $end];
            }
            foreach ($spans as [$start, $end]) {
                $merged[] = [$start, $end, $value];
            }
        }

        return $merged;
    }
}
