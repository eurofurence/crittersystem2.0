<?php

namespace App\Service;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Mercure\ShiftSignal;
use App\Repository\NeededVolunteerTypeRepository;
use App\Repository\ShiftEntryRepository;
use App\Exception\CapacityConflictException;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\Shift\CheckInPolicy;
use App\Service\Shift\ShiftConcurrency;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encapsulates the sign-up and cancellation rules.
 *
 * Certification requirements are NOT enforced on sign-up; {@see signUpError()}
 * marks the hook where that check belongs.
 */
final class ShiftSignupService
{
    private const KEY_LAST_UNSUBSCRIBE = 'event.last_unsubscribe';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftEntryRepository $entries,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly NeededVolunteerTypeRepository $needed,
        private readonly EventConfigStore $config,
        private readonly CheckInPolicy $checkIn,
        private readonly ShiftConcurrency $concurrency,
        private readonly ShiftSignal $live,
    ) {
    }

    /**
     * Per-type availability for a shift: a list of rows with the volunteer type,
     * the effective needed count, and how many are currently assigned.
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
     * Volunteer types the user may sign up as for this shift: requested by the
     * shift, the user is a confirmed member, and there is open capacity.
     *
     * @return array<int, VolunteerType> keyed by volunteer type id
     */
    public function signupOptions(Shift $shift, User $user): array
    {
        if ($shift->isPast() || $this->entries->findOneByShiftAndUser($shift, $user) !== null) {
            return [];
        }

        $options = [];
        foreach ($this->availability($shift) as $row) {
            if ($row['assigned'] < $row['needed'] && $this->memberships->isConfirmedMember($user, $row['type'])) {
                $options[$row['type']->getId()] = $row['type'];
            }
        }

        return $options;
    }

    /**
     * The user's state for a shift, for badges/filtering:
     * signed_up | past | full | overlap | ineligible | available.
     */
    public function eligibilityStatus(Shift $shift, User $user): string
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
        if ($this->entries->hasOverlap($user, $shift->getStartsAt(), $shift->getEndsAt(), $shift)) {
            return 'overlap';
        }

        return 'ineligible';
    }

    /**
     * First reason the user cannot sign up for this shift as this type, or null
     * when sign-up is allowed.
     */
    public function signUpError(User $user, Shift $shift, VolunteerType $type): ?string
    {
        if ($shift->isPast()) {
            return 'This shift has already ended.';
        }

        if ($this->entries->findOneByShiftAndUser($shift, $user) !== null) {
            return 'You are already signed up for this shift.';
        }

        if ($this->entries->hasOverlap($user, $shift->getStartsAt(), $shift->getEndsAt(), $shift)) {
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

        // TODO: reject sign-up when the user lacks a certification the volunteer
        // type requires. Certifications are recorded but not enforced here.

        $need = $this->needed->findEffectiveForShift($shift)[$type->getId()] ?? null;
        if ($need === null) {
            return 'This role is not requested for this shift.';
        }
        if ($this->entries->countForShiftAndType($shift, $type) >= $need->getCount()) {
            return 'This role is already fully staffed.';
        }

        return null;
    }

    /**
     * Sign the user up, guarding against the last-slot race: the shift
     * is write-locked so eligibility (which includes capacity) is re-checked
     * against a stable view, and the unique (shift,user) constraint is the final
     * backstop against a duplicate entry slipping through.
     */
    public function signUp(User $user, Shift $shift, VolunteerType $type, ?string $comment = null): ShiftEntry
    {
        // Pre-check outside the transaction for a fast, friendly error.
        $error = $this->signUpError($user, $shift, $type);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        try {
            return $this->concurrency->transactional(function () use ($user, $shift, $type, $comment): ShiftEntry {
                $this->concurrency->lockForUpdate($shift);

                // Re-check under the lock: capacity/overlap may have changed.
                $error = $this->signUpError($user, $shift, $type);
                if ($error !== null) {
                    throw new CapacityConflictException($error);
                }

                $entry = new ShiftEntry($shift, $type, $user);
                $entry->setUserComment($comment);
                $this->em->persist($entry);
                $this->em->flush();

                $this->live->staffingChanged($shift, $user);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CapacityConflictException('You are already signed up for this shift.');
        }
    }

    /**
     * Reason the entry cannot be cancelled by this actor, or null when allowed.
     * Managers may always cancel; volunteers only outside the unsubscribe window.
     */
    public function cancelError(ShiftEntry $entry, bool $isManager): ?string
    {
        if ($isManager) {
            return null;
        }

        $shift = $entry->getShift();
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

    public function cancel(ShiftEntry $entry): void
    {
        $shift = $entry->getShift();
        $user = $entry->getUser();

        $this->em->remove($entry);
        $this->em->flush();

        $this->live->staffingChanged($shift, $user);
    }
}
