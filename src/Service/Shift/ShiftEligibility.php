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
final class ShiftEligibility
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

    public function __construct(
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly NeededVolunteerTypeRepository $needed,
        private readonly CheckInPolicy $checkIn,
        private readonly UserCertificationRepository $certifications,
    ) {
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
        foreach ($this->needed->findEffectiveForShift($shift) as $need) {
            $type = $need->getVolunteerType();
            $rows[] = [
                'type' => $type,
                'needed' => $need->getCount(),
                'assigned' => $this->entries->countForShiftAndType($shift, $type),
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
        if ($shift->isPast() || $this->entries->findOneByShiftAndUser($shift, $user) !== null) {
            return [];
        }

        $options = [];
        foreach ($this->availability($shift) as $row) {
            // A role whose certification the volunteer lacks is not offered: signUpError refuses it,
            // so leaving it in the list puts a button on screen that can only fail. The reason is
            // still reachable - signUpError names the missing certification when asked.
            if ($row['assigned'] < $row['needed']
                && $this->memberships->isConfirmedMember($user, $row['type'])
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
        if ($this->entries->findOneByShiftAndUser($shift, $user) !== null) {
            return 'signed_up';
        }
        if ($shift->isPast()) {
            return 'past';
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
        if ($this->overlaps($user, $shift, $ignoreOverlapWith)) {
            return 'overlap';
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

        if ($this->entries->findOneByShiftAndUser($shift, $user) !== null) {
            return 'You are already signed up for this shift.';
        }

        if ($this->overlaps($user, $shift, $ignoreOverlapWith)) {
            return 'You are already booked for an overlapping shift.';
        }

        // Event-phase check-in gate: main-event shifts and shifts with
        // the per-shift override require the applicant to be checked in.
        if (($checkInError = $this->checkIn->checkInError($shift, $user)) !== null) {
            return $checkInError;
        }

        if (!$this->memberships->isConfirmedMember($user, $type)) {
            return 'You are not a confirmed member of this volunteer type.';
        }

        // The missing one is named: "you are not qualified" is not something a volunteer can act on,
        // and the name is what they take to whoever issues it.
        if (($missing = $this->missingCertifications($user, $type)) !== []) {
            return \sprintf(
                'This role requires %s, which you do not currently hold.',
                implode(', ', array_map(static fn (Certification $c): string => $c->getTitle(), $missing)),
            );
        }

        $need = $this->needed->findEffectiveForShift($shift)[$type->getId()] ?? null;
        if ($need === null) {
            return 'This role is not requested for this shift.';
        }
        if ($this->entries->countForShiftAndType($shift, $type) >= $need->getCount()) {
            return 'This role is already fully staffed.';
        }

        return null;
    }

    /** @param Shift[] $ignoreOverlapWith */
    private function overlaps(User $user, Shift $shift, array $ignoreOverlapWith): bool
    {
        // The shift itself is always excluded: an entry on it is "already signed up", not an
        // overlap, and the two produce different messages.
        return $this->entries->hasOverlap(
            $user,
            $shift->getStartsAt(),
            $shift->getEndsAt(),
            array_merge([$shift], $ignoreOverlapWith),
        );
    }
}
