<?php

namespace App\Service;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\Shift\GroupSignupPlan;
use App\Service\Shift\ShiftEligibility;
use App\Service\Shift\ShiftGroupResolver;
use App\Service\Shift\ShiftGroupSignupService;

/**
 * The sign-up and cancellation entry point for every volunteer-facing surface: the shift browser,
 * the staff apply screen and the Telegram bot.
 *
 * Shifts can be grouped, and a grouped shift is applied to and cancelled as a whole. That is
 * enforced here rather than in the callers, so a new surface cannot reach the single-shift behaviour
 * by accident: {@see signUp()} and {@see cancel()} always run the group path, which treats an
 * ungrouped shift as a group of one.
 *
 * The per-shift rules live in {@see ShiftEligibility}; the group writes in
 * {@see ShiftGroupSignupService}.
 */
final class ShiftSignupService
{
    public function __construct(
        private readonly ShiftEligibility $eligibility,
        private readonly ShiftGroupSignupService $groupSignup,
        private readonly ShiftGroupResolver $groups,
    ) {
    }

    /**
     * Per-type availability for a shift: a list of rows with the volunteer type, the effective
     * needed count, and how many are currently assigned.
     *
     * @return list<array{type: VolunteerType, needed: int, assigned: int}>
     */
    public function availability(Shift $shift): array
    {
        return $this->eligibility->availability($shift);
    }

    /**
     * Volunteer types the user may sign up as for this shift.
     *
     * For a grouped shift these are the roles offered on the shift itself; the roles on the siblings
     * are resolved from it (see {@see plan()}), because the volunteer picks once and commits to the
     * whole group.
     *
     * @return array<string, VolunteerType> keyed by the type's public uuid
     */
    public function signupOptions(Shift $shift, User $user): array
    {
        // A group with a member this volunteer may not see offers nothing at all, and says nothing
        // about why.
        if (!$this->groups->isFullyVisibleTo($shift, $user)) {
            return [];
        }

        return $this->eligibility->signupOptions($shift, $user);
    }

    /**
     * The user's state for a shift, for badges and filtering:
     * signed_up | past | full | overlap | ineligible | available.
     *
     * Grouped shifts report the state of the whole commitment. A shift whose sibling is full is not
     * "available": offering it would put a button on screen that can only fail.
     */
    public function eligibilityStatus(Shift $shift, User $user): string
    {
        $members = $this->groups->membersFor($shift);
        if (\count($members) === 1) {
            return $this->eligibility->eligibilityStatus($shift, $user);
        }

        if (!$this->groups->isFullyVisibleTo($shift, $user)) {
            return 'ineligible';
        }

        $own = $this->eligibility->eligibilityStatus($shift, $user, $this->groups->siblingsOf($shift));
        if ($own !== 'available') {
            return $own;
        }

        // Ranked worst-first: the state the volunteer needs to see is the one that blocks them.
        foreach (['past', 'full', 'overlap', 'ineligible'] as $blocking) {
            foreach ($members as $member) {
                if ($member === $shift) {
                    continue;
                }
                $siblings = array_values(array_filter($members, static fn (Shift $m): bool => $m !== $member));
                $state = $this->eligibility->eligibilityStatus($member, $user, $siblings);
                if ($state === $blocking) {
                    return $blocking;
                }
            }
        }

        return 'available';
    }

    /**
     * First reason the user cannot sign up for this shift as this type, or null when sign-up is
     * allowed. For a grouped shift this is the first reason across the whole group.
     */
    public function signUpError(User $user, Shift $shift, VolunteerType $type): ?string
    {
        return $this->plan($user, $shift, $type)->error;
    }

    /**
     * What applying would actually commit the volunteer to: every member shift, the role on each,
     * the added hours, and anything that blocks it. Read by the confirmation modal, the bot and the
     * submit handlers so all three agree.
     *
     * @param array<string, int> $typeChoices member shift uuid => volunteer type id
     */
    public function plan(User $user, Shift $shift, ?VolunteerType $type, array $typeChoices = []): GroupSignupPlan
    {
        return $this->groupSignup->plan($user, $shift, $type, $typeChoices);
    }

    /**
     * Sign the user up. A grouped shift signs them up for every member of the group, or for none.
     *
     * @param array<string, int> $typeChoices member shift uuid => volunteer type id
     *
     * @return ShiftEntry the entry on the shift that was applied to
     */
    public function signUp(
        User $user,
        Shift $shift,
        VolunteerType $type,
        ?string $comment = null,
        array $typeChoices = [],
        bool $acknowledgeHours = false,
    ): ShiftEntry {
        $created = $this->groupSignup->signUpGroup($user, $shift, $type, $typeChoices, $comment, $acknowledgeHours);

        foreach ($created as $entry) {
            if ($entry->getShift() === $shift) {
                return $entry;
            }
        }

        // Every member already held an entry except the siblings; the caller asked about this shift,
        // so hand back whatever exists for it rather than inventing one.
        return $created[0] ?? throw new \RuntimeException('You are already signed up for this shift.');
    }

    /**
     * Reason the entry cannot be cancelled by this actor, or null when allowed.
     * Managers may always cancel; volunteers only outside the unsubscribe window, and a grouped
     * commitment only while every member is still cancellable.
     */
    public function cancelError(ShiftEntry $entry, bool $isManager): ?string
    {
        return $this->groupSignup->cancelGroupError($entry, $isManager);
    }

    /** Cancel the sign-up. A grouped shift cancels every member entry the user holds. */
    public function cancel(ShiftEntry $entry): void
    {
        $this->groupSignup->cancelGroup($entry);
    }
}
