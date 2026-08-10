<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\User;
use App\Service\EventConfigStore;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Event-phase check-in rules for public shift application.
 *
 * - Main-event public shifts normally require the volunteer's event check-in
 *   (the "arrived" flag) before applying, preventing pre-event application.
 * - Setup and teardown public shifts do not require event check-in by default,
 *   because normal check-in may be unavailable during those periods.
 * - A shift may enable `Require Check-in`, which forces the requirement in every
 *   phase - setup and teardown included - for identity/security-sensitive work.
 *
 * When the event phase cannot be determined (event dates unconfigured) only the
 * per-shift override applies, so nothing is gated purely by an unknown phase.
 */
final class CheckInPolicy
{
    public const PHASE_SETUP = 'setup';
    public const PHASE_MAIN = 'main';
    public const PHASE_TEARDOWN = 'teardown';

    public function __construct(
        private readonly EventConfigStore $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** The event phase a shift falls in, or null when event dates are unset. */
    public function phaseOf(Shift $shift): ?string
    {
        $eventStart = $this->config->getDate(EventConfigStore::KEY_EVENT_START);
        $eventEnd = $this->config->getDate(EventConfigStore::KEY_EVENT_END);
        if ($eventStart === null || $eventEnd === null) {
            return null;
        }

        $start = $shift->getStartsAt();
        if ($start < $eventStart) {
            return self::PHASE_SETUP;
        }
        if ($start >= $eventEnd) {
            return self::PHASE_TEARDOWN;
        }

        return self::PHASE_MAIN;
    }

    /** Whether applying to this shift requires the applicant to be checked in. */
    public function requiresCheckin(Shift $shift): bool
    {
        if ($shift->isRequireCheckin()) {
            return true; // per-shift override applies in every phase
        }

        // Only the main event requires check-in by default; setup/teardown and an
        // unknown phase are exempt.
        return $this->phaseOf($shift) === self::PHASE_MAIN;
    }

    public function isCheckedIn(User $user): bool
    {
        return $user->getState()?->isArrived() ?? false;
    }

    /**
     * A blocking reason when the user cannot apply for lack of check-in, or null.
     */
    public function checkInError(Shift $shift, User $user): ?string
    {
        if ($this->requiresCheckin($shift) && !$this->isCheckedIn($user)) {
            return $this->translator->trans('shift.refusal.not_checked_in');
        }

        return null;
    }
}
