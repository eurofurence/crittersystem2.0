<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Mercure\ShiftSignal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists planner edits as draft shifts. Draft shifts are created
 * and mutated here so the grid autosaves without publishing; they stay invisible
 * to browsing/application until {@see PublicationService} publishes them.
 *
 * Painting is consolidated (crab.fit-style): a set of painted
 * intervals is merged so overlapping or touching spans become a single shift
 * rather than one shift per raster cell.
 */
final class PlannerDraftStore
{
    /** Visual raster and the minimum shift length. */
    public const RASTER_MINUTES = 30;
    public const MIN_DURATION_MINUTES = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftSignal $live,
    ) {
    }

    /**
     * Create a single draft shift. Enforces the 30-minute minimum duration;
     * exact minute-precision start/end are accepted.
     *
     * @throws \InvalidArgumentException on an invalid interval
     */
    public function createDraft(
        Department $department,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ShiftAudience $audience = ShiftAudience::PUBLIC_VOLUNTEER,
        ?ShiftTask $task = null,
        ?Location $location = null,
        ?User $author = null,
        ?string $title = null,
        ?string $description = null,
    ): Shift {
        $this->assertInterval($startsAt, $endsAt);
        $this->assertDescription($description);

        $shift = (new Shift())
            ->setTitle($title ?? $this->defaultTitle($task, $department))
            ->setDescription($description)
            ->setStartsAt($startsAt)
            ->setEndsAt($endsAt)
            ->setDepartment($department)
            ->setAudience($audience)
            ->setState(ShiftState::DRAFT)
            ->setShiftTask($task)
            ->setLocation($location);
        if ($author !== null) {
            $shift->setCreatedBy($author)->setUpdatedBy($author);
        }

        $this->em->persist($shift);
        $this->em->flush();

        $this->live->staffingChanged($shift);

        return $shift;
    }

    /**
     * Create draft shifts from painted intervals, consolidating overlapping or
     * touching spans into single shifts. Intervals are given as
     * [start, end] pairs; the returned shifts are the merged result.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $intervals
     *
     * @return list<Shift>
     */
    public function createConsolidated(
        Department $department,
        array $intervals,
        ShiftAudience $audience = ShiftAudience::PUBLIC_VOLUNTEER,
        ?ShiftTask $task = null,
        ?Location $location = null,
        ?User $author = null,
        ?string $title = null,
    ): array {
        $shifts = [];
        foreach ($this->consolidateIntervals($intervals) as [$start, $end]) {
            $shifts[] = $this->createDraft($department, $start, $end, $audience, $task, $location, $author, $title);
        }

        return $shifts;
    }

    /**
     * Merge overlapping or touching intervals.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $intervals
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function consolidateIntervals(array $intervals): array
    {
        $valid = array_values(array_filter(
            $intervals,
            static fn ($i) => $i[1] > $i[0],
        ));
        usort($valid, static fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($valid as [$start, $end]) {
            if ($merged !== [] && $start <= $merged[\count($merged) - 1][1]) {
                $last = &$merged[\count($merged) - 1];
                if ($end > $last[1]) {
                    $last[1] = $end;
                }
                unset($last);
                continue;
            }
            $merged[] = [$start, $end];
        }

        return $merged;
    }

    /**
     * Update a single shift's editable details from the side panel or Add Shift
     * modal. Only the provided keys are changed. Autosaves.
     *
     * Everything is validated before anything is written: a rejected field has to leave the managed
     * entity untouched, or the flush would persist half an edit.
     *
     * Present-but-empty is meaningful for two keys, so they are read with array_key_exists rather
     * than isset: an empty description clears the description, and a null `shiftGroup` takes the
     * shift out of its group. Absent leaves either where it is. The caller is responsible for having
     * checked that a group it passes shares the shift's department.
     *
     * @param array{title?: string, description?: ?string, task?: ?ShiftTask, audience?: ShiftAudience, location?: ?Location, requireCheckin?: bool, startsAt?: \DateTimeImmutable, endsAt?: \DateTimeImmutable} $fields
     */
    public function updateDetails(Shift $shift, array $fields, ?User $author = null): Shift
    {
        if (\array_key_exists('description', $fields)) {
            $this->assertDescription($fields['description']);
        }
        if (\array_key_exists('startsAt', $fields) || \array_key_exists('endsAt', $fields)) {
            $start = $fields['startsAt'] ?? $shift->getStartsAt();
            $end = $fields['endsAt'] ?? $shift->getEndsAt();
            $this->assertInterval($start, $end);
            $shift->setStartsAt($start)->setEndsAt($end);
        }
        if (isset($fields['title']) && $fields['title'] !== '') {
            $shift->setTitle($fields['title']);
        }
        if (\array_key_exists('description', $fields)) {
            $shift->setDescription($fields['description']);
        }
        if (\array_key_exists('task', $fields)) {
            $shift->setShiftTask($fields['task']);
        }
        if (\array_key_exists('shiftGroup', $fields)) {
            $shift->setShiftGroup($fields['shiftGroup']);
        }
        if (isset($fields['audience'])) {
            $shift->setAudience($fields['audience']);
        }
        if (\array_key_exists('location', $fields)) {
            $shift->setLocation($fields['location']);
        }
        if (\array_key_exists('requireCheckin', $fields)) {
            $shift->setRequireCheckin((bool) $fields['requireCheckin']);
        }
        if ($author !== null) {
            $shift->setUpdatedBy($author);
        }
        $this->em->flush();

        $this->live->staffingChanged($shift);

        return $shift;
    }

    /**
     * Set a shift's duration in minutes, keeping the start fixed (batch-edit
     * duration). Enforces the minimum.
     */
    public function setDuration(Shift $shift, int $minutes, ?User $author = null): Shift
    {
        return $this->reschedule($shift, $shift->getStartsAt(), $shift->getStartsAt()->modify("+{$minutes} minutes"), $author);
    }

    /**
     * Upsert a staffing requirement on a shift (batch-edit volunteer types and
     * required quantities). A count of 0 removes the requirement.
     */
    public function setNeededVolunteerType(Shift $shift, VolunteerType $type, int $count): void
    {
        $existing = null;
        foreach ($shift->getNeededVolunteerTypes() as $need) {
            if ($need->getVolunteerType() === $type) {
                $existing = $need;
                break;
            }
        }

        if ($count <= 0) {
            if ($existing !== null) {
                $shift->removeNeededVolunteerType($existing);
                $this->em->remove($existing);
            }
        } elseif ($existing !== null) {
            $existing->setCount($count);
        } else {
            $need = new NeededVolunteerType($type, $count);
            $shift->addNeededVolunteerType($need);
            $this->em->persist($need);
        }
        $this->em->flush();

        $this->live->staffingChanged($shift);
    }

    /** Move/resize a shift, keeping it valid. Autosaves in place. */
    public function reschedule(Shift $shift, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, ?User $author = null): Shift
    {
        $this->assertInterval($startsAt, $endsAt);
        $shift->setStartsAt($startsAt)->setEndsAt($endsAt);
        if ($author !== null) {
            $shift->setUpdatedBy($author);
        }
        $this->em->flush();

        $this->live->staffingChanged($shift);

        return $shift;
    }

    /**
     * Signals before the flush: afterwards the entity is detached and its department is no longer
     * reachable to address the update to.
     */
    public function delete(Shift $shift): void
    {
        $this->live->staffingChanged($shift);

        $this->em->remove($shift);
        $this->em->flush();
    }

    /**
     * The planner writes entities straight through, so the entity's Assert\Length never runs here.
     *
     * @throws \InvalidArgumentException
     */
    private function assertDescription(?string $description): void
    {
        if ($description !== null && mb_strlen($description) > Shift::DESCRIPTION_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('The description may not exceed %d characters.', Shift::DESCRIPTION_MAX_LENGTH));
        }
    }

    private function assertInterval(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): void
    {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('The shift must end after it starts.');
        }
        $minutes = ($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 60;
        if ($minutes < self::MIN_DURATION_MINUTES) {
            throw new \InvalidArgumentException(\sprintf('A shift must be at least %d minutes long.', self::MIN_DURATION_MINUTES));
        }
    }

    private function defaultTitle(?ShiftTask $task, Department $department): string
    {
        return $task?->getName() ?? $department->getName().' shift';
    }
}
