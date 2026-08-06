<?php

namespace App\Service\Assignment;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Certification;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftPosition;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Mercure\ShiftSignal;
use App\Enum\ShiftEntryState;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\Availability\AvailabilityService;
use App\Service\EventConfigStore;
use App\Service\HoursCalculator;
use App\Service\Shift\PositionService;
use App\Service\Shift\ShiftGroupResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manager-driven shift/position assignment. Surfaces the warnings a
 * manager should see before publishing (availability Avoid/Unavailable/occupied,
 * over recommended event hours) and requires an explicit override to assign
 * against them. Overrides are visibly marked on the entry and audited.
 *
 * Shift groups propagate here: assigning somebody to one member assigns them to all of them, and
 * removing them takes them off all of them, because a grouped shift is one commitment. A manager can
 * still staff a single member by passing $groupSplit, which is audited - breaking the group is a
 * decision on the record rather than the silent default.
 *
 * Every manager path that creates or deletes a ShiftEntry routes through here, so the audit trail,
 * the live signal and the group rules cannot be reached around.
 */
final class ManualAssignmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftEntryRepository $entries,
        private readonly AvailabilityService $availability,
        private readonly PositionService $positions,
        private readonly HoursCalculator $hours,
        private readonly EventConfigStore $config,
        private readonly ShiftGroupResolver $groups,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly AuditLogger $audit,
        private readonly ShiftSignal $live,
        private readonly \App\Service\Shift\ShiftEligibility $eligibility,
    ) {
    }

    /**
     * Warnings a manager should weigh before assigning this user to this shift.
     * Each carries a machine key and a human message.
     *
     * A grouped shift is inspected as the whole commitment: the hours are the group's hours and an
     * availability clash on any member is named. A manager confirming the assignment has to see what
     * it actually commits the volunteer to.
     *
     * @return array{needsOverride: bool, warnings: list<array{key: string, message: string}>, members: list<Shift>, missing: list<Shift>}
     */
    public function inspect(Shift $shift, User $user, ?VolunteerType $type = null): array
    {
        return $this->inspectMembers($this->groups->membersFor($shift), $user, $type);
    }

    /**
     * The same inspection over an explicit member list, so a manager splitting the group is warned
     * about the one shift they are actually assigning rather than about shifts they chose to leave
     * out.
     *
     * @param list<Shift> $members
     *
     * @return array{needsOverride: bool, warnings: list<array{key: string, message: string}>, members: list<Shift>, missing: list<Shift>}
     */
    private function inspectMembers(array $members, User $user, ?VolunteerType $type = null): array
    {
        $missing = $this->missingMembers($members, $user);
        $grouped = \count($members) > 1;

        $warnings = [];
        $needsOverride = false;

        foreach ($missing as $member) {
            $label = $grouped ? \sprintf('"%s": ', $member->getTitle()) : '';
            // Members of one group are taken together, so one must never be reported as occupying
            // another.
            $siblings = array_values(array_filter($members, static fn (Shift $m): bool => $m !== $member));
            $state = $this->availability->planningState($user, $member->getStartsAt(), $member->getEndsAt(), $member, $siblings);

            if ($state['occupied']) {
                $needsOverride = true;
                $warnings[] = ['key' => 'occupied', 'message' => $label.'The user already has a confirmed assignment overlapping this shift.'];
            } elseif ($state['value'] !== null && $state['value']->needsOverride()) {
                $needsOverride = true;
                $warnings[] = ['key' => 'availability', 'message' => $label.\sprintf('The user marked this time as "%s".', $state['value']->label())];
            }
        }

        $recommended = $this->config->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, EventConfigStore::DEFAULT_HOURS_RECOMMENDED_MAX);
        $added = 0.0;
        foreach ($missing as $member) {
            $added += $member->getDurationHours();
        }
        $projected = $this->hours->totalForUser($user) + $added;
        if ($recommended > 0 && $projected > $recommended) {
            $needsOverride = true;
            $warnings[] = ['key' => 'hours', 'message' => \sprintf('This would bring the user to %.1f planned hours (recommended max %d).', $projected, $recommended)];
        }

        // A volunteer signing themselves up is refused outright when they lack a certification the
        // role requires. A manager is not - they may be holding the paper certificate as they type -
        // but they are told, and the override is recorded on the entry so the placement is not a
        // silent exception.
        if ($type !== null) {
            $missingCertifications = $this->eligibility->missingCertifications($user, $type);
            if ($missingCertifications !== []) {
                $needsOverride = true;
                $warnings[] = [
                    'key' => 'certification',
                    'message' => \sprintf(
                        'The user does not hold %s, which this role requires.',
                        implode(', ', array_map(static fn (Certification $c): string => $c->getTitle(), $missingCertifications)),
                    ),
                ];
            }
        }

        return ['needsOverride' => $needsOverride, 'warnings' => $warnings, 'members' => $members, 'missing' => $missing];
    }

    /**
     * Assign a user to a shift as the given volunteer type, returning the entry on that shift.
     *
     * A grouped shift assigns every member unless $groupSplit says otherwise. When warnings apply the
     * caller must pass $override; otherwise this refuses. An override is marked on the entry and
     * audited, as is a group split.
     *
     * @throws \RuntimeException when an override is required but not confirmed
     */
    public function assign(
        Shift $shift,
        User $user,
        VolunteerType $type,
        bool $override = false,
        ?User $actor = null,
        bool $groupSplit = false,
    ): ShiftEntry {
        $existing = $this->entries->findOneByShiftAndUser($shift, $user);
        $members = $groupSplit ? [$shift] : $this->groups->membersFor($shift);
        $missing = $this->missingMembers($members, $user);

        if ($missing === []) {
            return $existing ?? throw new \RuntimeException('The assignment could not be recorded.');
        }

        $inspection = $this->inspectMembers($members, $user, $type);
        if ($inspection['needsOverride'] && !$override) {
            $messages = array_map(static fn ($w) => $w['message'], $inspection['warnings']);
            throw new \RuntimeException('This assignment needs an override: '.implode(' ', $messages));
        }

        $created = [];
        foreach ($missing as $member) {
            $entry = new ShiftEntry($member, $this->typeForMember($member, $type, $user), $user);
            $entry->setState(ShiftEntryState::ASSIGNMENT);
            if ($inspection['needsOverride']) {
                $entry->markOverridden(implode('; ', array_map(static fn ($w) => $w['key'], $inspection['warnings'])));
            }
            $this->em->persist($entry);
            $created[] = $entry;
        }
        $this->em->flush();

        foreach ($created as $entry) {
            $this->live->staffingChanged($entry->getShift(), $user);
            $this->audit->log(AuditEvents::SHIFT, AuditEvents::ASSIGN, [
                'resourceType' => 'shift', 'resourceId' => (string) $entry->getShift()->getId(), 'resourceOwnerId' => $user->getId(),
                'details' => ['user' => $user->getUserIdentifier()],
            ]);
            if ($entry->isOverridden()) {
                $this->audit->log(AuditEvents::SHIFT, AuditEvents::OVERRIDE, [
                    'resourceType' => 'shift', 'resourceId' => (string) $entry->getShift()->getId(), 'resourceOwnerId' => $user->getId(),
                    'details' => ['reason' => $entry->getOverrideReason()],
                ]);
            }
        }

        if ($groupSplit && $shift->isGrouped()) {
            $this->auditGroupSplit($shift, $user, 'assign');
        }

        return $this->entries->findOneByShiftAndUser($shift, $user)
            ?? $created[0]
            ?? throw new \RuntimeException('The assignment could not be recorded.');
    }

    /**
     * Assign a user to a named position, ensuring their shift entry first.
     *
     * The named position exists only on its own shift, so a grouped shift gets the position here and
     * a plain entry on each sibling. Passing no type lets the position's own resolution rules pick
     * one, which is what the matrix grid relies on.
     *
     * @throws \RuntimeException when no volunteer type can be resolved
     */
    public function assignToPosition(
        ShiftPosition $shiftPosition,
        User $user,
        ?VolunteerType $type = null,
        bool $override = false,
        ?User $actor = null,
        bool $groupSplit = false,
    ): void {
        $type ??= $this->positions->resolveEntryType($shiftPosition, $user);
        if (!$type instanceof VolunteerType) {
            throw new \RuntimeException('No volunteer type is available to record this assignment. Give the position a required volunteer type, or confirm the volunteer in one.');
        }

        $this->assign($shiftPosition->getShift(), $user, $type, $override, $actor, $groupSplit);
        $this->positions->assignUser($shiftPosition, $user, $type);
    }

    /**
     * Remove a user's assignment; audited.
     *
     * A grouped shift takes the user off every member, because a half-kept commitment is what the
     * group exists to prevent. $groupSplit removes only this one, and records that it did.
     */
    public function remove(ShiftEntry $entry, bool $groupSplit = false): void
    {
        $user = $entry->getUser();
        $origin = $entry->getShift();
        $removed = $groupSplit ? [$entry] : $this->groups->entriesFor($origin, $user);
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
            $this->audit->log(AuditEvents::SHIFT, AuditEvents::UNASSIGN, [
                'resourceType' => 'shift', 'resourceId' => (string) $shift->getId(), 'resourceOwnerId' => $user->getId(),
            ]);
        }

        if ($groupSplit && $origin->isGrouped()) {
            $this->auditGroupSplit($origin, $user, 'unassign');
        }
    }

    /**
     * Members of the group this user is not on yet.
     *
     * @param Shift[] $members
     *
     * @return list<Shift>
     */
    public function missingMembers(array $members, User $user): array
    {
        return array_values(array_filter(
            $members,
            fn (Shift $member): bool => $this->entries->findOneByShiftAndUser($member, $user) === null,
        ));
    }

    /**
     * The role a sibling is recorded under: the requested one when the sibling asks for it, otherwise
     * one the sibling asks for and the user is confirmed in, otherwise the requested one anyway.
     *
     * Manager assignment deliberately does not enforce membership or capacity - that is what makes it
     * an override - so this only improves the recorded role, it never refuses.
     */
    private function typeForMember(Shift $member, VolunteerType $requested, User $user): VolunteerType
    {
        $offered = [];
        foreach ($member->getNeededVolunteerTypes() as $need) {
            if ($need->getVolunteerType() === $requested) {
                return $requested;
            }
            $offered[] = $need->getVolunteerType();
        }

        foreach ($offered as $type) {
            if ($this->memberships->isConfirmedMember($user, $type)) {
                return $type;
            }
        }

        return $requested;
    }

    private function auditGroupSplit(Shift $shift, User $user, string $action): void
    {
        $this->audit->log(AuditEvents::SHIFT, AuditEvents::OVERRIDE, [
            'resourceType' => 'shift', 'resourceId' => (string) $shift->getId(), 'resourceOwnerId' => $user->getId(),
            'details' => [
                'reason' => 'group_split',
                'action' => $action,
                'group' => $shift->getShiftGroup()?->getName(),
            ],
        ]);
    }
}
