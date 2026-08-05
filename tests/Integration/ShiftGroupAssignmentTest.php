<?php

namespace App\Tests\Integration;

use App\Audit\AuditEvents;
use App\Entity\AuditEvent;
use App\Entity\Department;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftGroup;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftState;
use App\Service\Assignment\AutoAssignmentPlanner;
use App\Service\Assignment\ManualAssignmentService;
use App\Tests\DatabaseTestCase;

/**
 * Manager-side propagation across a shift group.
 *
 * A grouped shift is one commitment, so assigning somebody puts them on every member and removing
 * them takes them off every member. Splitting the group is possible, deliberate and recorded, and
 * the automatic planner proposes a group as one candidate or not at all.
 */
final class ShiftGroupAssignmentTest extends DatabaseTestCase
{
    private Department $department;
    private VolunteerType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = new Department('D '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->department);
        $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($this->type);
        $this->em->flush();
    }

    private function assignments(): ManualAssignmentService
    {
        return static::getContainer()->get(ManualAssignmentService::class);
    }

    private function planner(): AutoAssignmentPlanner
    {
        return static::getContainer()->get(AutoAssignmentPlanner::class);
    }

    private function user(): User
    {
        $user = new User();
        $user->setName('u'.bin2hex(random_bytes(3)))
            ->setEmail(bin2hex(random_bytes(4)).'@e.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $this->em->persist($user);

        $membership = new UserVolunteerType($user, $this->type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    private function shift(string $title, string $start, string $end, int $capacity = 1, ?VolunteerType $type = null): Shift
    {
        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($this->department);
        $shift->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);

        $need = new NeededVolunteerType($type ?? $this->type, $capacity);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();

        return $shift;
    }

    /** @param Shift[] $shifts */
    private function group(array $shifts): ShiftGroup
    {
        $group = new ShiftGroup($this->department, 'Main Show');
        $this->em->persist($group);
        foreach ($shifts as $shift) {
            $group->addShift($shift);
        }
        $this->em->flush();

        return $group;
    }

    private function entryCount(User $user): int
    {
        return $this->em->getRepository(ShiftEntry::class)->count(['user' => $user->getId()]);
    }

    /** @return AuditEvent[] */
    private function auditEvents(string $action): array
    {
        return $this->em->getRepository(AuditEvent::class)->findBy(['action' => $action]);
    }

    public function testAssigningOneMemberAssignsEveryMember(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $this->assignments()->assign($show, $user, $this->type);

        self::assertSame(2, $this->entryCount($user));
    }

    public function testRemovingOneMemberRemovesEveryMember(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $entry = $this->assignments()->assign($show, $user, $this->type);
        $this->assignments()->remove($entry);

        self::assertSame(0, $this->entryCount($user));
    }

    public function testTheSplitOptionAssignsOnlyOneMemberAndIsAudited(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $this->assignments()->assign($show, $user, $this->type, groupSplit: true);

        self::assertSame(1, $this->entryCount($user));

        $splits = array_filter(
            $this->auditEvents(AuditEvents::OVERRIDE),
            static fn (AuditEvent $event): bool => ($event->getDetails()['reason'] ?? null) === 'group_split',
        );
        self::assertNotEmpty($splits, 'Breaking a group must leave a record.');
    }

    public function testTheSplitOptionRemovesOnlyOneMember(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $entry = $this->assignments()->assign($show, $user, $this->type);
        $this->assignments()->remove($entry, groupSplit: true);

        self::assertSame(1, $this->entryCount($user), 'Only the one shift goes; the sibling stays.');
    }

    public function testInspectCountsTheWholeGroupsHours(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $inspection = $this->assignments()->inspect($show, $user);

        self::assertCount(2, $inspection['members']);
        self::assertCount(2, $inspection['missing']);
    }

    public function testTheAutoPlannerProposesAGroupAsOneUnit(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $proposal = $this->planner()->propose($this->department);

        $shiftIds = [];
        foreach ($proposal->getAssignments() as $suggestion) {
            if ($suggestion->getUser()->getId() === $user->getId()) {
                $shiftIds[] = $suggestion->getShift()->getId();
            }
        }

        self::assertContains($rehearsal->getId(), $shiftIds);
        self::assertContains($show->getId(), $shiftIds, 'A group is proposed whole, so the manager sees the full commitment.');
    }

    public function testTheAutoPlannerSkipsAGroupWhoseMembersNeedDifferentRoles(): void
    {
        $this->user();
        $other = new VolunteerType('Steward '.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $this->em->flush();

        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00', 1, $other);
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $proposal = $this->planner()->propose($this->department);

        self::assertCount(
            0,
            $proposal->getAssignments(),
            'Guessing a different role per member is the manager\'s call, not the planner\'s.',
        );
    }

    public function testApplyingAGroupedProposalCountsItOnce(): void
    {
        $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $proposal = $this->planner()->propose($this->department);
        $applied = $this->planner()->apply($proposal);

        self::assertSame(1, $applied, 'Assigning the first member already creates the sibling, so it counts once.');
    }

    public function testStaffingPageAssignmentIsAuditedAndSignalled(): void
    {
        // Phase 0 of the shift-group work: the staffing screen used to persist entries inline, which
        // left no audit trail and pushed no live update to anyone else's open screen.
        $user = $this->user();
        $shift = $this->shift('Standalone', '2036-06-01 12:00', '2036-06-01 13:00');

        $this->assignments()->assign($shift, $user, $this->type, override: true);

        self::assertNotEmpty($this->auditEvents(AuditEvents::ASSIGN));
    }
}
