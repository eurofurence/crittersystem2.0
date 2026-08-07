<?php

namespace App\Api\Bot;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Service\Shift\ShiftGroupResolver;
use App\Service\ShiftSignupService;

/**
 * Shapes shifts for the /api/bot JSON contract.
 *
 * Identifiers are public UUIDs throughout, never the internal integer primary
 * keys - see App\Entity\Concern\HasPublicUuid.
 */
final class BotShiftNormalizer
{
    public function __construct(
        private readonly ShiftSignupService $signup,
        private readonly ShiftGroupResolver $groups,
    ) {
    }

    /** @return array<string, mixed> */
    public function shift(Shift $shift, ?User $actor = null): array
    {
        $availability = $this->signup->availability($shift);
        $needed = array_sum(array_column($availability, 'needed'));
        $assigned = array_sum(array_column($availability, 'assigned'));

        $neededTypes = [];
        foreach ($availability as $row) {
            $certifications = [];
            foreach ($row['type']->getCertifications() as $certification) {
                $certifications[] = $certification->getTitle();
            }

            $neededTypes[] = [
                'id' => (string) $row['type']->getUuid(),
                'name' => $row['type']->getName(),
                'needed' => $row['needed'],
                'assigned' => $row['assigned'],
                'certifications' => $certifications,
            ];
        }

        $location = $shift->getLocation();
        $task = $shift->getShiftTask();
        $department = $shift->getDepartment();
        $state = $actor !== null ? $this->signup->eligibilityStatus($shift, $actor) : null;

        return [
            'id' => (string) $shift->getUuid(),
            'title' => $shift->getTitle(),
            'description' => $shift->getDescription() ?? '',
            'department_id' => $department !== null ? (string) $department->getUuid() : null,
            'department_name' => $department?->getName() ?? '',
            'location_id' => $location !== null ? (string) $location->getUuid() : null,
            'location_name' => $location?->getName() ?? '',
            'shift_task_id' => $task !== null ? (string) $task->getUuid() : null,
            'shift_task_name' => $task?->getName() ?? '',
            'start' => $shift->getStartsAt()->format(\DATE_ATOM),
            'end' => $shift->getEndsAt()->format(\DATE_ATOM),
            'total_slots' => $needed,
            'open_slots' => max(0, $needed - $assigned),
            'needed_types' => $neededTypes,
            'status' => $this->status($shift, $needed, $assigned),
            'staff_only' => $shift->getAudience()->isStaffOnly(),
            'map_url' => $location?->getMapUrl(),
            'my_state' => $state,
            'my_state_reason' => $this->refusal($shift, $actor, $state),
            'group' => $this->group($shift, $actor),
        ];
    }

    /**
     * Why the shift is closed to this volunteer, in the same words every other surface uses, so the
     * bot never has to answer "ineligible" with nothing they can do about it.
     *
     * Null when there is nothing to explain: they can apply, or they are already on it.
     */
    private function refusal(Shift $shift, ?User $actor, ?string $state): ?string
    {
        if ($actor === null || $state === 'available' || $state === 'signed_up') {
            return null;
        }

        return $this->signup->refusalFor($actor, $shift);
    }

    /**
     * Shifts that can only be taken together with this one, so the bot can show the volunteer what
     * applying commits them to before it applies.
     *
     * Null when the shift is not grouped, and null when any member is one the caller may not see:
     * the group is then not applicable at all, and naming the hidden member would confirm that it
     * exists.
     *
     * @return array<string, mixed>|null
     */
    private function group(Shift $shift, ?User $actor): ?array
    {
        $group = $shift->getShiftGroup();
        if (!$shift->isGrouped() || $group === null || !$this->groups->isFullyVisibleTo($shift, $actor)) {
            return null;
        }

        $shifts = [];
        foreach ($this->groups->membersFor($shift) as $member) {
            $memberLocation = $member->getLocation();
            $shifts[] = [
                'id' => (string) $member->getUuid(),
                'title' => $member->getTitle(),
                'start' => $member->getStartsAt()->format(\DATE_ATOM),
                'end' => $member->getEndsAt()->format(\DATE_ATOM),
                'location_name' => $memberLocation?->getName() ?? '',
                'is_this_shift' => $member === $shift,
            ];
        }

        return [
            'id' => (string) $group->getUuid(),
            'name' => $group->getName(),
            'description' => $group->getDescription() ?? '',
            'shifts' => $shifts,
        ];
    }

    /**
     * Derived once here so the bot and the web UI cannot disagree about what a
     * shift's state is. Time beats staffing: a finished shift reads "finished"
     * however many slots were left open.
     *
     * VMS's own ShiftState (draft|published) is a publication state and is
     * unrelated - draft shifts are never exposed to the bot at all.
     */
    private function status(Shift $shift, int $needed, int $assigned): string
    {
        $now = new \DateTimeImmutable();

        return match (true) {
            $shift->getEndsAt() < $now => 'finished',
            $shift->getStartsAt() <= $now => 'running',
            $needed > 0 && $assigned >= $needed => 'full',
            $assigned === 0 => 'empty',
            default => 'understaffed',
        };
    }

    /** @return array<string, mixed> */
    public function entry(ShiftEntry $entry): array
    {
        return [
            'id' => (string) $entry->getUuid(),
            'shift_id' => (string) $entry->getShift()->getUuid(),
            'user_id' => (string) $entry->getUser()->getUuid(),
            'volunteer_type_id' => (string) $entry->getVolunteerType()->getUuid(),
            'state' => $entry->getState()->value,
            'noshow' => $entry->isNoshow(),
            'checked_in_at' => $entry->getCheckedInAt()?->format(\DATE_ATOM),
            'checked_out_at' => $entry->getCheckedOutAt()?->format(\DATE_ATOM),
        ];
    }
}
