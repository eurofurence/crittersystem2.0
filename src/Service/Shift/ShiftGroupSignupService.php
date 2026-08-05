<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Exception\CapacityConflictException;
use App\Mercure\ShiftSignal;
use App\Repository\ShiftEntryRepository;
use App\Service\Assignment\EventHoursGuard;
use App\Service\EventConfigStore;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sign-up and cancellation across a whole shift group.
 *
 * This is the general implementation, not a special case: an ungrouped shift is a group of one, so
 * there is a single code path and a shift that is grouped later cannot fall through an older
 * single-shift branch. {@see \App\Service\ShiftSignupService} is the facade every caller uses.
 *
 * The promise the feature makes is all or nothing. If any member refuses, the whole application is
 * refused and no entry survives - which is why the writes run inside one transaction with every
 * member locked, and why eligibility is re-evaluated under those locks.
 */
final class ShiftGroupSignupService
{
    private const KEY_LAST_UNSUBSCRIBE = 'event.last_unsubscribe';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftEligibility $eligibility,
        private readonly ShiftGroupResolver $groups,
        private readonly ShiftEntryRepository $entries,
        private readonly ShiftConcurrency $concurrency,
        private readonly EventHoursGuard $hoursGuard,
        private readonly EventConfigStore $config,
        private readonly ShiftSignal $live,
    ) {
    }

    /**
     * What applying to this shift would commit the volunteer to, without writing anything.
     *
     * @param array<string, int> $typeChoices member shift uuid => volunteer type id, for members
     *                                        whose role the volunteer had to pick
     */
    public function plan(User $user, Shift $shift, ?VolunteerType $requestedType, array $typeChoices = []): GroupSignupPlan
    {
        $members = $this->groups->membersFor($shift);
        $group = $shift->isGrouped() ? $shift->getShiftGroup() : null;

        // A group holding a shift this volunteer may not see is not applicable, and the refusal must
        // not describe the hidden member or even confirm it exists. No member list is built at all.
        if (!$this->groups->isFullyVisibleTo($shift, $user)) {
            return new GroupSignupPlan(
                $shift,
                $group,
                [],
                0.0,
                false,
                'This shift is not open for sign-up at the moment.',
                true,
            );
        }

        $rows = [];
        $addedHours = 0.0;
        $alreadyOnAll = true;

        foreach ($members as $member) {
            $siblings = array_values(array_filter($members, static fn (Shift $m): bool => $m !== $member));
            $entry = $this->entries->findOneByShiftAndUser($member, $user);
            if ($entry === null) {
                $alreadyOnAll = false;
                // Hours are a property of the shift, not of the role, so they are counted before the
                // role is resolved. Otherwise the total shown in the modal would move as the
                // volunteer works through the dropdowns.
                $addedHours += $member->getDurationHours();
            }

            $options = $this->eligibility->signupOptions($member, $user);
            $isOrigin = $member === $shift;
            $type = $entry?->getVolunteerType() ?? $this->resolveType($member, $isOrigin, $requestedType, $options, $typeChoices);

            $error = null;
            $warnings = [];
            if ($entry === null) {
                if ($type === null) {
                    $error = $options === [] ? $this->refusalFor($user, $member, $siblings) : null;
                } else {
                    $error = $this->eligibility->signUpError($user, $member, $type, $siblings);
                }
            } else {
                $warnings[] = 'You are already signed up for this shift.';
            }

            [$assigned, $needed] = $this->capacityFor($member, $type);

            $rows[] = new GroupSignupMember(
                $member,
                $isOrigin,
                $type,
                $options,
                $error,
                $warnings,
                $assigned,
                $needed,
                $entry,
            );
        }

        $error = null;
        if ($alreadyOnAll) {
            $error = \count($members) > 1
                ? 'You are already signed up for every shift in this group.'
                : 'You are already signed up for this shift.';
        } else {
            foreach ($rows as $row) {
                if ($row->error !== null) {
                    $error = \count($members) > 1
                        ? \sprintf('"%s" cannot be taken: %s', $row->shift->getTitle(), lcfirst($row->error))
                        : $row->error;
                    break;
                }
            }
        }

        $max = $this->hoursGuard->recommendedMax();
        $needsAck = $max > 0 && ($this->hoursGuard->plannedHours($user) + $addedHours) > $max;

        return new GroupSignupPlan($shift, $group, $rows, $addedHours, $needsAck, $error);
    }

    /**
     * Sign the volunteer up for every member of the group.
     *
     * Guards the last-slot race the same way the single-shift path always has, widened to the whole
     * group: every member is write-locked in primary-key order, eligibility is re-checked against
     * that stable view, and the unique (shift, user) constraint is the final backstop. Locking in a
     * fixed order matters - two volunteers applying from opposite members of the same group would
     * otherwise deadlock.
     *
     * @param array<string, int> $typeChoices member shift uuid => volunteer type id
     *
     * @return list<ShiftEntry> the entries created, in member order
     *
     * @throws \RuntimeException         when the application is refused up front
     * @throws CapacityConflictException when it is refused under the lock
     */
    public function signUpGroup(
        User $user,
        Shift $shift,
        ?VolunteerType $requestedType,
        array $typeChoices = [],
        ?string $comment = null,
        bool $acknowledgeHours = false,
    ): array {
        // Pre-check outside the transaction for a fast, friendly error.
        $plan = $this->plan($user, $shift, $requestedType, $typeChoices);
        $this->assertPlanUsable($plan, $acknowledgeHours, static fn (string $m) => new \RuntimeException($m));

        try {
            $created = $this->concurrency->transactional(function () use ($user, $shift, $requestedType, $typeChoices, $comment, $acknowledgeHours): array {
                foreach ($this->groups->membersForUpdate($shift) as $member) {
                    $this->concurrency->lockForUpdate($member);
                }

                // Re-check under the locks: capacity, overlaps and hours may have moved.
                $plan = $this->plan($user, $shift, $requestedType, $typeChoices);
                $this->assertPlanUsable($plan, $acknowledgeHours, static fn (string $m) => new CapacityConflictException($m));

                $entries = [];
                foreach ($plan->members as $row) {
                    if ($row->existingEntry !== null || $row->type === null) {
                        continue;
                    }
                    $entry = new ShiftEntry($row->shift, $row->type, $user);
                    $entry->setUserComment($comment);
                    $this->em->persist($entry);
                    $entries[] = $entry;
                }
                $this->em->flush();

                return $entries;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CapacityConflictException('You are already signed up for this shift.');
        }

        // After the commit: a signal that overtakes its own transaction tells the browser to re-read
        // a row that is not there yet.
        foreach ($created as $entry) {
            $this->live->staffingChanged($entry->getShift(), $user);
        }

        return $created;
    }

    /**
     * Reason the volunteer cannot cancel, or null when allowed. Managers may always cancel.
     *
     * A grouped commitment is cancelled as a whole, so the strictest member decides. Once any member
     * has started or ended, dropping the rest is the department's decision, not a self-service
     * action: the volunteer is sent to a manager instead.
     */
    public function cancelGroupError(ShiftEntry $entry, bool $isManager): ?string
    {
        if ($isManager) {
            return null;
        }

        $shift = $entry->getShift();
        if (!$shift->isGrouped()) {
            return $this->singleCancelError($shift);
        }

        $now = new \DateTimeImmutable();
        $windowHours = (int) $this->config->get(self::KEY_LAST_UNSUBSCRIBE, 0);

        foreach ($this->groups->entriesFor($shift, $entry->getUser()) as $held) {
            $member = $held->getShift();

            if ($member->getStartsAt() <= $now) {
                return \sprintf(
                    'These shifts are taken together and "%s" has already started. Contact a manager to be taken off them.',
                    $member->getTitle(),
                );
            }
            if ($windowHours > 0 && $now > $member->getStartsAt()->modify(\sprintf('-%d hours', $windowHours))) {
                return \sprintf(
                    'These shifts are taken together and cancellation for "%s" closed %d hour(s) before it starts. Contact a manager to be taken off them.',
                    $member->getTitle(),
                    $windowHours,
                );
            }
        }

        return null;
    }

    /**
     * Cancel the volunteer's entries across the whole group.
     *
     * @return list<ShiftEntry> the entries removed
     */
    public function cancelGroup(ShiftEntry $entry): array
    {
        $user = $entry->getUser();
        $removed = $this->groups->entriesFor($entry->getShift(), $user);
        if ($removed === []) {
            $removed = [$entry];
        }

        $shifts = [];
        foreach ($removed as $held) {
            $shifts[] = $held->getShift();
            $this->em->remove($held);
        }
        $this->em->flush();

        foreach ($shifts as $shift) {
            $this->live->staffingChanged($shift, $user);
        }

        return $removed;
    }

    /** Today's single-shift rule, unchanged: past shifts and the unsubscribe window. */
    private function singleCancelError(Shift $shift): ?string
    {
        if ($shift->isPast()) {
            return 'This shift has already ended.';
        }

        $windowHours = (int) $this->config->get(self::KEY_LAST_UNSUBSCRIBE, 0);
        if ($windowHours > 0) {
            $deadline = $shift->getStartsAt()->modify(\sprintf('-%d hours', $windowHours));
            if (new \DateTimeImmutable() > $deadline) {
                return \sprintf('Cancellation closed %d hour(s) before the shift starts.', $windowHours);
            }
        }

        return null;
    }

    /** @param callable(string): \RuntimeException $exception */
    private function assertPlanUsable(GroupSignupPlan $plan, bool $acknowledgeHours, callable $exception): void
    {
        if ($plan->error !== null) {
            throw $exception($plan->error);
        }
        if ($plan->needsChoice()) {
            throw $exception('Choose a role for every shift in this group.');
        }
        // Only a grouped application asks for the acknowledgement here; a single shift keeps the
        // acknowledgement where its own screen already handles it.
        if ($plan->isGrouped() && $plan->needsHoursAcknowledgement && !$acknowledgeHours) {
            throw $exception(\sprintf(
                'These shifts add %.1f hours and would take you past the recommended maximum of %d. Confirm to continue.',
                $plan->totalHours,
                $this->hoursGuard->recommendedMax(),
            ));
        }
    }

    /**
     * The role a member would be recorded under.
     *
     * On the shift the volunteer actually clicked the requested role is used verbatim, never
     * substituted: the per-shift check then reports the real reason it is refused. Silently
     * recording somebody under a role they did not pick would be worse than refusing them.
     *
     * On a sibling, in order:
     * 1. an explicit choice the volunteer made for that member;
     * 2. the role they picked on the shift they clicked, when the sibling also offers it;
     * 3. the only remaining eligible role;
     * 4. null, meaning the volunteer has to choose.
     *
     * @param array<int, VolunteerType> $options
     * @param array<string, int>        $typeChoices
     */
    private function resolveType(Shift $member, bool $isOrigin, ?VolunteerType $requested, array $options, array $typeChoices): ?VolunteerType
    {
        if ($isOrigin) {
            return $requested;
        }

        $chosen = $typeChoices[(string) $member->getUuid()] ?? null;
        if ($chosen !== null && isset($options[$chosen])) {
            return $options[$chosen];
        }

        if ($requested !== null && isset($options[$requested->getId()])) {
            return $options[$requested->getId()];
        }

        return \count($options) === 1 ? reset($options) : null;
    }

    /**
     * Why this member is refused when no role is on offer at all. Probed against a role the shift
     * actually asks for, since the per-shift check needs one.
     *
     * @param Shift[] $siblings
     */
    private function refusalFor(User $user, Shift $member, array $siblings): string
    {
        $availability = $this->eligibility->availability($member);
        if ($availability === []) {
            return 'This shift is not open for sign-up.';
        }

        return $this->eligibility->signUpError($user, $member, $availability[0]['type'], $siblings)
            ?? 'You are not eligible for this shift.';
    }

    /** @return array{0: int, 1: int} assigned and needed for the resolved role, or the whole shift */
    private function capacityFor(Shift $member, ?VolunteerType $type): array
    {
        $assigned = 0;
        $needed = 0;
        foreach ($this->eligibility->availability($member) as $row) {
            if ($type !== null && $row['type'] !== $type) {
                continue;
            }
            $assigned += $row['assigned'];
            $needed += $row['needed'];
        }

        return [$assigned, $needed];
    }
}
