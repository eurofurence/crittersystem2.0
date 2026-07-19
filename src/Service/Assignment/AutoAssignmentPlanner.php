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
use Doctrine\ORM\EntityManagerInterface;

/**
 * Automatic assignment proposal engine. It only ever produces a draft
 * {@see AssignmentProposal} - it never creates published assignments. Suggestions
 * respect the hard constraints (membership, capacity, confirmed existing
 * assignments, overlap prohibition, explicit Unavailable) and are ranked by soft
 * constraints (Preferred before Available before Avoid, then fair distribution of
 * planned hours).
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
        private readonly AuditLogger $audit,
    ) {
    }

    public function propose(Department $department, ?User $actor = null): AssignmentProposal
    {
        $proposal = new AssignmentProposal($department, $actor);
        $this->em->persist($proposal);

        // Per-user state accumulated across the proposal.
        $proposedIntervals = []; // userId => list<[start,end]>
        $projectedHours = [];    // userId => float

        foreach ($this->shifts->findBy(['department' => $department, 'state' => ShiftState::PUBLISHED->value], ['startsAt' => 'ASC']) as $shift) {
            foreach ($shift->getNeededVolunteerTypes() as $need) {
                $type = $need->getVolunteerType();
                $open = $need->getCount() - $this->entries->countForShiftAndType($shift, $type);
                if ($open <= 0) {
                    continue;
                }

                $candidates = $this->rankedCandidates($shift, $type, $proposedIntervals);
                foreach (array_slice($candidates, 0, $open) as $candidate) {
                    [$user, $value] = $candidate;
                    new ProposalAssignment($proposal, $shift, $user, $type, $value);

                    $proposedIntervals[$user->getId()][] = [$shift->getStartsAt(), $shift->getEndsAt()];
                    $projectedHours[$user->getId()] = ($projectedHours[$user->getId()] ?? $this->hours->totalForUser($user)) + $shift->getDurationHours();
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
     * Eligible candidates for a shift/type, hard-filtered and soft-ranked.
     *
     * @param array<int, list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>> $proposedIntervals
     *
     * @return list<array{0: User, 1: \App\Enum\AvailabilityValue|null}>
     */
    private function rankedCandidates(Shift $shift, VolunteerType $type, array $proposedIntervals): array
    {
        $out = [];
        foreach ($this->memberships->findByVolunteerType($type) as $membership) {
            $user = $membership->getUser();
            if (!$this->memberships->isConfirmedMember($user, $type)) {
                continue; // hard: confirmed Volunteer Type eligibility
            }
            if ($this->entries->findOneByShiftAndUser($shift, $user) !== null) {
                continue; // hard: not already assigned
            }

            $state = $this->availability->planningState($user, $shift->getStartsAt(), $shift->getEndsAt(), $shift);
            if ($state['occupied']) {
                continue; // hard: confirmed existing assignment
            }
            if ($state['value'] !== null && $state['value'] === \App\Enum\AvailabilityValue::UNAVAILABLE) {
                continue; // hard: explicit Unavailable
            }
            if ($this->overlapsProposed($user, $shift, $proposedIntervals)) {
                continue; // hard: overlap prohibition within this proposal
            }

            $out[] = [$user, $state['value'], $this->projected($user)];
        }

        // Soft ranking: willingness first (Preferred < Available < Avoid), then
        // fewer planned hours for fair distribution.
        usort($out, static function (array $a, array $b): int {
            $ra = $a[1]?->rank() ?? 1; // undeclared treated like "available"
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
     * assignments (publication is the manager's explicit action).
     * Suggestions that now conflict are skipped. Returns the number applied.
     */
    public function apply(AssignmentProposal $proposal, ?User $actor = null): int
    {
        if ($proposal->getStatus() !== ProposalStatus::DRAFT) {
            return 0;
        }

        $applied = 0;
        foreach ($proposal->getAssignments() as $suggestion) {
            try {
                $this->manual->assign($suggestion->getShift(), $suggestion->getUser(), $suggestion->getVolunteerType(), false, $actor);
                ++$applied;
            } catch (\RuntimeException) {
                // A suggestion that became invalid (capacity/availability changed)
                // is skipped rather than force-published.
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
