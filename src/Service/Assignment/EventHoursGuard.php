<?php

namespace App\Service\Assignment;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Shift;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Service\HoursCalculator;
use App\Service\Shift\ShiftGroupResolver;

/**
 * Recommended event-hours threshold. The admin configures a
 * recommended maximum of planned hours from setup through teardown; this is a
 * warning threshold, not a hard limit. Self-application beyond it requires the
 * user's explicit acknowledgement and manager assignment an override - both
 * audited. Changing the configuration never invalidates existing assignments.
 */
final class EventHoursGuard
{
    public function __construct(
        private readonly HoursCalculator $hours,
        private readonly EventConfigStore $config,
        private readonly ShiftGroupResolver $groups,
        private readonly AuditLogger $audit,
    ) {
    }

    public function recommendedMax(): int
    {
        return $this->config->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, EventConfigStore::DEFAULT_HOURS_RECOMMENDED_MAX);
    }

    /** Total planned (projected) hours across all of the user's sign-ups. */
    public function plannedHours(User $user): float
    {
        return $this->hours->totalForUser($user);
    }

    public function isOver(User $user): bool
    {
        $max = $this->recommendedMax();

        return $max > 0 && $this->plannedHours($user) > $max;
    }

    /** Hours above the recommendation (0 when within it). */
    public function overBy(User $user): float
    {
        $max = $this->recommendedMax();

        return $max > 0 ? max(0.0, $this->plannedHours($user) - $max) : 0.0;
    }

    /** Whether taking this shift alone would push the user beyond the recommendation. */
    public function wouldExceed(User $user, Shift $shift): bool
    {
        $max = $this->recommendedMax();

        return $max > 0 && ($this->plannedHours($user) + $shift->getDurationHours()) > $max;
    }

    /**
     * Whether taking this shift would push the user beyond the recommendation, counting the whole
     * shift group.
     *
     * A grouped shift commits the volunteer to every member at once, so weighing one member's
     * duration would let somebody acknowledge one hour and receive seven.
     */
    public function wouldExceedGroup(User $user, Shift $shift): bool
    {
        $max = $this->recommendedMax();

        return $max > 0 && ($this->plannedHours($user) + $this->groups->totalDurationHours($shift)) > $max;
    }

    /** Record a user's explicit acknowledgement of exceeding the threshold. */
    public function acknowledgeSelfApplication(User $user, Shift $shift): void
    {
        $this->audit->log(AuditEvents::SHIFT, AuditEvents::ACKNOWLEDGE, [
            'resourceType' => 'shift',
            'resourceId' => (string) $shift->getId(),
            'resourceOwnerId' => $user->getId(),
            'details' => [
                'plannedHours' => $this->plannedHours($user),
                'recommendedMax' => $this->recommendedMax(),
                'addedHours' => $this->groups->totalDurationHours($shift),
                'group' => $shift->getShiftGroup()?->getName(),
            ],
        ]);
    }
}
