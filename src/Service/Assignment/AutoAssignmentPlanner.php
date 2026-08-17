<?php

namespace App\Service\Assignment;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\AssignmentProposal;
use App\Entity\Department;
use App\Entity\ProposalAssignment;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ProposalStatus;
use App\Enum\ShiftState;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\Availability\AvailabilityService;
use App\Service\HoursCalculator;
use App\Service\Shift\ShiftGroupResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Automatic assignment proposal engine. It only ever produces a draft
 * {@see AssignmentProposal} - it never creates published assignments. Suggestions
 * respect the hard constraints (membership, capacity, confirmed existing
 * assignments, overlap prohibition, explicit Unavailable) and are ranked by soft
 * constraints (Preferred before Available before Avoid, then fair distribution of
 * planned hours).
 *
 * A shift group is one indivisible candidate: a volunteer is proposed for it only when every member
 * clears the hard constraints, and the proposal then carries one suggestion per member so the
 * manager reviewing it sees the whole commitment. Proposing somebody for half a group would produce
 * exactly the partial assignment the group exists to prevent.
 *
 * A group is only proposed for a role every member asks for. One whose members need different roles
 * is left to manual assignment rather than guessed at: choosing a different role per member is a
 * judgement the manager has to make, and a wrong guess is a volunteer booked into the wrong place.
 */
final class AutoAssignmentPlanner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftRepository $shifts,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly ShiftEntryRepository $entries,
        private readonly AvailabilityService $availability,
        private readonly HoursCalculator $hours,
        private readonly ManualAssignmentService $manual,
        private readonly ShiftGroupResolver $groups,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Build a draft proposal for every published shift in the department.
     *
     * A group whose members are not all published is skipped: it is not applicable to anybody yet.
     * When a volunteer is proposed for a group, the whole group's hours are charged to them at once,
     * because counting only the first member would let the fair-distribution ranking keep picking
     * somebody who is already committed to the rest of it.
     */
    public function propose(Department $department, ?User $actor = null): AssignmentProposal
    {
        $proposal = new AssignmentProposal($department, $actor);
        $this->em->persist($proposal);

        $proposedIntervals = []; // userId => list<[start,end]>
        $projectedHours = [];    // userId => float
        $doneGroups = [];        // group id => true, so each group is considered exactly once

        foreach ($this->shifts->findBy(['department' => $department, 'state' => ShiftState::PUBLISHED->value], ['startsAt' => 'ASC']) as $shift) {
            $members = $this->groups->membersFor($shift);

            if (\count($members) > 1) {
                $groupId = $shift->getShiftGroup()?->getId();
                if ($groupId === null || isset($doneGroups[$groupId])) {
                    continue;
                }
                $doneGroups[$groupId] = true;

                foreach ($members as $member) {
                    if ($member->getState() !== ShiftState::PUBLISHED) {
                        continue 2;
                    }
                }
            }

            foreach ($this->openSlots($members) as [$type, $open]) {
                $candidates = $this->rankedCandidates($members, $type, $proposedIntervals);
                foreach (\array_slice($candidates, 0, $open) as $candidate) {
                    [$user, $value] = $candidate;

                    $groupHours = 0.0;
                    foreach ($members as $member) {
                        new ProposalAssignment($proposal, $member, $user, $type, $value);
                        $proposedIntervals[$user->getId()][] = [$member->getStartsAt(), $member->getEndsAt()];
                        $groupHours += $member->getDurationHours();
                    }

                    $projectedHours[$user->getId()] = ($projectedHours[$user->getId()] ?? $this->hours->totalForUser($user)) + $groupHours;
                }
            }
        }

        $this->em->flush();
        $this->audit->log(AuditEvents::SHIFT, AuditEvents::CREATE, [
            'resourceType' => 'assignment_proposal', 'resourceId' => (string) $proposal->getId(),
            'details' => ['suggestions' => $proposal->getAssignments()->count()],
        ]);

        return $proposal;
    }

    /**
     * Roles still open, with how many volunteers can be proposed for each.
     *
     * For a single shift that is its own open count. For a group it is a role every member asks for,
     * capped by the tightest member: proposing more would fill one member and overflow another.
     *
     * @param list<Shift> $members
     *
     * @return list<array{0: VolunteerType, 1: int}>
     */
    private function openSlots(array $members): array
    {
        $origin = $members[0];
        $slots = [];

        foreach ($origin->getNeededVolunteerTypes() as $need) {
            $type = $need->getVolunteerType();
            $open = $need->getCount() - $this->entries->countForShiftAndType($origin, $type);

            foreach (\array_slice($members, 1) as $member) {
                $memberOpen = null;
                foreach ($member->getNeededVolunteerTypes() as $memberNeed) {
                    if ($memberNeed->getVolunteerType() === $type) {
                        $memberOpen = $memberNeed->getCount() - $this->entries->countForShiftAndType($member, $type);
                        break;
                    }
                }
                $open = $memberOpen === null ? 0 : min($open, $memberOpen);
            }

            if ($open > 0) {
                $slots[] = [$type, $open];
            }
        }

        return $slots;
    }

    /**
     * Eligible candidates for a shift (or a whole group) and role, hard-filtered and soft-ranked.
     * A candidate must clear every member; the suggestion is ranked and recorded under the worst
     * declared availability across them.
     *
     * The other members of the group are excluded from each member's occupancy check: they are taken
     * together with it, so reading them as occupying the volunteer would rule out every group.
     *
     * Soft ranking is willingness first (Preferred before Available before Avoid), then fewer planned
     * hours for fair distribution. An undeclared availability ranks as Available.
     *
     * @param list<Shift>                                                          $members
     * @param array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $proposedIntervals
     *
     * @return list<array{0: User, 1: \App\Enum\AvailabilityValue|null}>
     */
    private function rankedCandidates(array $members, VolunteerType $type, array $proposedIntervals): array
    {
        $out = [];
        foreach ($this->memberships->findByVolunteerType($type) as $membership) {
            $user = $membership->getUser();
            if (!$this->memberships->isConfirmedMember($user, $type)) {
                continue;
            }

            $worst = null;
            $eligible = true;
            foreach ($members as $member) {
                if ($this->entries->findOneByShiftAndUser($member, $user) !== null) {
                    $eligible = false;
                    break;
                }

                $siblings = array_values(array_filter($members, static fn (Shift $m): bool => $m !== $member));
                $state = $this->availability->planningState($user, $member->getStartsAt(), $member->getEndsAt(), $member, $siblings);
                if ($state['occupied']) {
                    $eligible = false;
                    break;
                }
                if ($state['value'] !== null && $state['value'] === \App\Enum\AvailabilityValue::UNAVAILABLE) {
                    $eligible = false;
                    break;
                }
                if ($this->overlapsProposed($user, $member, $proposedIntervals)) {
                    $eligible = false;
                    break;
                }

                if ($state['value'] !== null && ($worst === null || $state['value']->rank() > $worst->rank())) {
                    $worst = $state['value'];
                }
            }

            if ($eligible) {
                $out[] = [$user, $worst, $this->projected($user)];
            }
        }

        usort($out, static function (array $a, array $b): int {
            $ra = $a[1]?->rank() ?? 1;
            $rb = $b[1]?->rank() ?? 1;

            return $ra <=> $rb ?: $a[2] <=> $b[2];
        });

        return array_map(static fn (array $c) => [$c[0], $c[1]], $out);
    }

    /** @param array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $proposedIntervals */
    private function overlapsProposed(User $user, Shift $shift, array $proposedIntervals): bool
    {
        foreach ($proposedIntervals[$user->getId()] ?? [] as [$start, $end]) {
            if ($start < $shift->getEndsAt() && $end > $shift->getStartsAt()) {
                return true;
            }
        }

        return false;
    }

    private function projected(User $user): float
    {
        return $this->hours->totalForUser($user);
    }

    /**
     * Apply a draft proposal, converting its suggestions into confirmed
     * assignments (publication is the manager's explicit action). Returns the number applied.
     *
     * A suggestion whose entry already exists, whether from a sibling applied earlier in this run or
     * from an assignment made elsewhere, is skipped, and so is one that has since become invalid
     * because capacity or availability moved: it is never force-published.
     *
     * A grouped suggestion carries one row per member, and assigning the first of them already puts
     * the volunteer on the rest. The count therefore follows the entries actually created rather than
     * the rows walked, or a group of three would be reported as three separate assignments.
     */
    public function apply(AssignmentProposal $proposal, ?User $actor = null): int
    {
        if ($proposal->getStatus() !== ProposalStatus::DRAFT) {
            return 0;
        }

        $applied = 0;
        foreach ($proposal->getAssignments() as $suggestion) {
            if ($this->entries->findOneByShiftAndUser($suggestion->getShift(), $suggestion->getUser()) !== null) {
                continue;
            }
            try {
                $this->manual->assign($suggestion->getShift(), $suggestion->getUser(), $suggestion->getVolunteerType(), false, $actor);
                ++$applied;
            } catch (\RuntimeException) {
            }
        }
        $proposal->setStatus(ProposalStatus::APPLIED);
        $this->em->flush();

        $this->audit->log(AuditEvents::SHIFT, AuditEvents::PUBLISH, [
            'resourceType' => 'assignment_proposal', 'resourceId' => (string) $proposal->getId(),
            'details' => ['applied' => $applied],
        ]);

        return $applied;
    }

    public function discard(AssignmentProposal $proposal): void
    {
        $proposal->setStatus(ProposalStatus::DISCARDED);
        $this->em->flush();
    }
}
