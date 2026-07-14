<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;

/**
 * Shift Wizard: generate repeated draft shifts by slicing each
 * selected day's [start, end] window into fixed-duration slots. Created shifts
 * remain drafts until published, reusing {@see PlannerDraftStore}.
 */
final class ShiftWizardService
{
    public function __construct(private readonly PlannerDraftStore $drafts)
    {
    }

    /**
     * @param list<string>                     $dates       Y-m-d day strings
     * @param list<array{0: VolunteerType, 1: int}> $neededTypes [type, quantity] pairs
     *
     * @return list<Shift> the created draft shifts
     *
     * @throws \InvalidArgumentException on invalid input
     */
    public function generate(
        Department $department,
        array $dates,
        string $startTime,
        string $endTime,
        int $slotMinutes,
        \DateTimeZone $tz,
        ShiftAudience $audience = ShiftAudience::PUBLIC_VOLUNTEER,
        ?ShiftTask $task = null,
        ?Location $location = null,
        array $neededTypes = [],
        ?User $author = null,
    ): array {
        if ($slotMinutes < PlannerDraftStore::MIN_DURATION_MINUTES) {
            throw new \InvalidArgumentException(\sprintf('Slot duration must be at least %d minutes.', PlannerDraftStore::MIN_DURATION_MINUTES));
        }
        if ($dates === []) {
            throw new \InvalidArgumentException('Select at least one date.');
        }

        $created = [];
        foreach ($dates as $date) {
            foreach ($this->slotsForDay($date, $startTime, $endTime, $slotMinutes, $tz) as [$start, $end]) {
                $shift = $this->drafts->createDraft($department, $start, $end, $audience, $task, $location, $author);
                foreach ($neededTypes as [$type, $count]) {
                    if ($type instanceof VolunteerType && (int) $count > 0) {
                        $this->drafts->setNeededVolunteerType($shift, $type, (int) $count);
                    }
                }
                $created[] = $shift;
            }
        }

        return $created;
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     *
     * @throws \InvalidArgumentException
     */
    public function slotsForDay(string $date, string $startTime, string $endTime, int $slotMinutes, \DateTimeZone $tz): array
    {
        try {
            $dayStart = new \DateTimeImmutable($date.' '.$startTime, $tz);
            $dayEnd = new \DateTimeImmutable($date.' '.$endTime, $tz);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid date or time.');
        }

        // An end at or before the start means the window runs past midnight.
        if ($dayEnd <= $dayStart) {
            $dayEnd = $dayEnd->modify('+1 day');
        }

        $slots = [];
        $cursor = $dayStart;
        // Guard the loop; a day can hold at most 48 half-hour slots plus overnight.
        for ($i = 0; $i < 96; ++$i) {
            $slotEnd = $cursor->modify("+{$slotMinutes} minutes");
            if ($slotEnd > $dayEnd) {
                break; // drop a trailing partial slot shorter than the duration
            }
            $slots[] = [$cursor, $slotEnd];
            $cursor = $slotEnd;
        }

        return $slots;
    }
}
