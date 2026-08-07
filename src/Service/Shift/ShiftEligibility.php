<?php

namespace App\Service\Shift;

use App\Entity\Certification;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\NeededVolunteerTypeRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserCertificationRepository;
use App\Repository\UserVolunteerTypeRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The per-shift sign-up rules, with no knowledge of shift groups.
 *
 * Extracted from {@see \App\Service\ShiftSignupService} so that the single-shift and whole-group
 * writers can share one implementation of "may this volunteer take this shift as this role". If the
 * two ever answered differently, a group application would pass its own checks and then be refused
 * by the surface that offered it.
 *
 * A role's certification requirements are enforced here: a volunteer who does not currently hold
 * one is neither offered the role nor accepted for it, and the refusal names what is missing.
 */
final class ShiftEligibility implements ResetInterface
{
    /**
     * Certification ids each user currently holds, filled once per user.
     *
     * A shift list asks about every shift on the page, and the answer cannot change while one
     * request is being served. This service is never used from a worker, so nothing keeps the
     * instance alive past that.
     *
     * @var array<int, array<int, true>>
     */
    private array $heldCertifications = [];

    /** The user {@see warmUp()} was called for, or null while nothing is preloaded. */
    private ?int $warmUserId = null;

    /** @var array<int, array<int, \App\Entity\NeededVolunteerType>> shift id => type id => need */
    private array $warmNeeds = [];

    /** @var array<int, array<int, int>> shift id => type id => booked count */
    private array $warmAssigned = [];

    /** @var array<int, \App\Entity\ShiftEntry> shift id => the warm user's own entry */
    private array $warmOwnEntries = [];

    /** @var array<int, true> volunteer type ids the warm user is a confirmed member of */
    private array $warmConfirmedTypes = [];

    /** @var list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int}> the warm user's bookings */
    private array $warmBookings = [];

    public function __construct(
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly NeededVolunteerTypeRepository $needed,
        private readonly CheckInPolicy $checkIn,
        private readonly UserCertificationRepository $certifications,
        private readonly OverlapPolicy $overlapPolicy,
    ) {
    }

    /**
     * Preload everything the rules ask about a list of shifts, so answering for a whole screen costs
     * a handful of queries instead of a handful per row.
     *
     * The rules themselves do not change: each lookup below consults the preloaded data when it
     * covers the question and falls back to its repository otherwise, so a warm and a cold instance
     * cannot answer differently.
     *
     * Read paths only, and always paired with {@see coolDown()} around the one render it serves.
     * What is preloaded are entities, and an entity outlives its entity manager badly: once that is
     * cleared or replaced the entity is detached, and handing a detached volunteer type to a new
     * sign-up makes Doctrine treat it as an unsaved record and refuse the write. The counts and the
     * volunteer's own entries are also exactly what a write changes, so nothing here may be left
     * standing for the next thing that happens.
     *
     * @param Shift[] $shifts
     */
    public function warmUp(User $user, array $shifts): void
    {
        $this->warmUserId = $user->getId();
        $this->warmNeeds = $this->needed->findEffectiveForShifts($shifts);
        $this->warmAssigned = $this->entries->assignedCountsForShifts($shifts);
        $this->warmOwnEntries = $this->entries->findByUserAndShifts($user, $shifts);
        $this->warmBookings = $this->entries->bookedIntervals($user);

        $this->warmConfirmedTypes = [];
        foreach ($this->memberships->findByUser($user) as $membership) {
            if ($membership->isConfirmed()) {
                $this->warmConfirmedTypes[$membership->getVolunteerType()->getId()] = true;
            }
        }
    }

    /**
     * Symfony empties this between requests and between messenger messages. The preload is scoped to
     * one render by its caller, but a leak would be silent and expensive to find: the rules would go
     * on answering for whoever was warmed, so a volunteer could be refused a shift because somebody
     * else is already on it.
     */
    public function reset(): void
    {
        $this->coolDown();
        $this->heldCertifications = [];
    }

    /** Drop everything {@see warmUp()} preloaded, so the rules answer from the database again. */
    public function coolDown(): void
    {
        $this->warmUserId = null;
        $this->warmNeeds = [];
        $this->warmAssigned = [];
        $this->warmOwnEntries = [];
        $this->warmConfirmedTypes = [];
        $this->warmBookings = [];
    }

    private function hasWarmShift(Shift $shift): bool
    {
        return isset($this->warmNeeds[$shift->getId()]);
    }

    private function isWarmUser(User $user): bool
    {
        return $this->warmUserId !== null && $this->warmUserId === $user->getId();
    }

    /** @return array<int, \App\Entity\NeededVolunteerType> */
    private function effectiveNeeds(Shift $shift): array
    {
        return $this->warmNeeds[$shift->getId()] ?? $this->needed->findEffectiveForShift($shift);
    }

    private function assignedCount(Shift $shift, VolunteerType $type): int
    {
        if ($this->hasWarmShift($shift)) {
            return $this->warmAssigned[$shift->getId()][$type->getId()] ?? 0;
        }

        return $this->entries->countForShiftAndType($shift, $type);
    }

    private function ownEntry(Shift $shift, User $user): ?\App\Entity\ShiftEntry
    {
        if ($this->isWarmUser($user) && $this->hasWarmShift($shift)) {
            return $this->warmOwnEntries[$shift->getId()] ?? null;
        }

        return $this->entries->findOneByShiftAndUser($shift, $user);
    }

    private function isConfirmedMember(User $user, VolunteerType $type): bool
    {
        if ($this->isWarmUser($user)) {
            return isset($this->warmConfirmedTypes[$type->getId()]);
        }

        return $this->memberships->isConfirmedMember($user, $type);
    }

    /** @param Shift[] $exclude */
    private function isDoubleBooked(User $user, \DateTimeImmutable $start, \DateTimeImmutable $end, array $exclude): bool
    {
        if (!$this->isWarmUser($user)) {
            return $this->entries->hasOverlap($user, $start, $end, $exclude);
        }

        $excludedIds = array_filter(array_map(static fn (?Shift $s): ?int => $s?->getId(), $exclude));
        foreach ($this->warmBookings as [$bookedStart, $bookedEnd, $shiftId]) {
            if (!\in_array($shiftId, $excludedIds, true) && $bookedStart < $end && $bookedEnd > $start) {
                return true;
            }
        }

        return false;
    }

    /**
     * The certifications this role requires and this volunteer does not currently hold.
     *
     * Expiry counts: somebody whose certificate ran out is not qualified today, which is the whole
     * reason the expiry is recorded.
     *
     * @return list<Certification>
     */
    public function missingCertifications(User $user, VolunteerType $type): array
    {
        $required = $type->getCertifications();
        if ($required->isEmpty()) {
            return [];
        }

        $held = $this->heldCertifications[$user->getId()] ??= $this->heldFor($user);

        $missing = [];
        foreach ($required as $certification) {
            if (!isset($held[$certification->getId()])) {
                $missing[] = $certification;
            }
        }

        return $missing;
    }

    /** @return array<int, true> */
    private function heldFor(User $user): array
    {
        $held = [];
        foreach ($this->certifications->findByUser($user) as $record) {
            if ($record->isValid()) {
                $held[$record->getCertification()->getId()] = true;
            }
        }

        return $held;
    }

    /**
     * Per-type availability for a shift: a list of rows with the volunteer type, the effective
     * needed count, and how many are currently assigned.
     *
     * @return list<array{type: VolunteerType, needed: int, assigned: int}>
     */
    public function availability(Shift $shift): array
    {
        $rows = [];
        foreach ($this->effectiveNeeds($shift) as $need) {
            $type = $need->getVolunteerType();
            $rows[] = [
                'type' => $type,
                'needed' => $need->getCount(),
                'assigned' => $this->assignedCount($shift, $type),
            ];
        }

        return $rows;
    }

    /**
     * Volunteer types the user may sign up as for this shift: requested by the shift, the user is a
     * confirmed member, and there is open capacity.
     *
     * @return array<string, VolunteerType> keyed by the type's public uuid
     */
    public function signupOptions(Shift $shift, User $user): array
    {
        if ($shift->isPast() || $this->ownEntry($shift, $user) !== null) {
            return [];
        }

        $options = [];
        foreach ($this->availability($shift) as $row) {
            if ($row['assigned'] < $row['needed']
                && $this->isConfirmedMember($user, $row['type'])
                && $this->missingCertifications($user, $row['type']) === []
            ) {
                $options[(string) $row['type']->getUuid()] = $row['type'];
            }
        }

        return $options;
    }

    /**
     * The user's state for a shift, for badges/filtering:
     * signed_up | past | full | overlap | ineligible | available.
     *
     * @param Shift[] $ignoreOverlapWith shifts that must not count as an overlap
     */
    public function eligibilityStatus(Shift $shift, User $user, array $ignoreOverlapWith = []): string
    {
        if ($this->ownEntry($shift, $user) !== null) {
            return 'signed_up';
        }
        if ($shift->isPast()) {
            return 'past';
        }
        if ($this->overlaps($user, $shift, $ignoreOverlapWith)) {
            return 'overlap';
        }
        if (!empty($this->signupOptions($shift, $user))) {
            return 'available';
        }

        $availability = $this->availability($shift);
        $needed = array_sum(array_column($availability, 'needed'));
        $assigned = array_sum(array_column($availability, 'assigned'));
        if ($needed > 0 && $assigned >= $needed) {
            return 'full';
        }

        return 'ineligible';
    }

    /**
     * First reason the user cannot sign up for this shift as this type, or null when sign-up is
     * allowed.
     *
     * @param Shift[] $ignoreOverlapWith shifts that must not count as an overlap
     */
    public function signUpError(User $user, Shift $shift, VolunteerType $type, array $ignoreOverlapWith = []): ?string
    {
        if ($shift->isPast()) {
            return 'This shift has already ended.';
        }

        if ($this->ownEntry($shift, $user) !== null) {
            return 'You are already signed up for this shift.';
        }

        if ($this->overlaps($user, $shift, $ignoreOverlapWith)) {
            return 'You are already booked for an overlapping shift.';
        }

        if (($checkInError = $this->checkIn->checkInError($shift, $user)) !== null) {
            return $checkInError;
        }

        if (!$this->isConfirmedMember($user, $type)) {
            return 'You are not a confirmed member of this volunteer type.';
        }

        if (($missing = $this->missingCertifications($user, $type)) !== []) {
            return \sprintf(
                'This role requires %s, which you do not currently hold.',
                implode(', ', array_map(static fn (Certification $c): string => $c->getTitle(), $missing)),
            );
        }

        $need = $this->effectiveNeeds($shift)[$type->getId()] ?? null;
        if ($need === null) {
            return 'This role is not requested for this shift.';
        }
        if ($this->assignedCount($shift, $type) >= $need->getCount()) {
            return 'This role is already fully staffed.';
        }

        return null;
    }

    /**
     * Whether a shift running at the same time stands in this user's way, which for staff it does
     * not - see {@see OverlapPolicy}.
     *
     * @param Shift[] $ignoreOverlapWith
     */
    private function overlaps(User $user, Shift $shift, array $ignoreOverlapWith): bool
    {
        if (!$this->overlapPolicy->blocks($user)) {
            return false;
        }

        // The shift itself is always excluded: an entry on it is "already signed up", not an
        // overlap, and the two produce different messages.
        return $this->isDoubleBooked(
            $user,
            $shift->getStartsAt(),
            $shift->getEndsAt(),
            array_merge([$shift], $ignoreOverlapWith),
        );
    }
}
