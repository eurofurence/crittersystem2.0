<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\AvailabilityValue;
use App\Enum\ProposalStatus;
use App\Enum\ShiftState;
use App\Service\Assignment\AutoAssignmentPlanner;
use App\Service\Availability\AvailabilityService;
use App\Tests\DatabaseTestCase;

/**
 * Automatic assignment proposal: it produces a draft proposal only -
 * never published assignments - and respects the hard constraints (membership,
 * capacity, explicit Unavailable).
 */
final class AutoAssignmentPlannerTest extends DatabaseTestCase
{
    private function planner(): AutoAssignmentPlanner
    {
        return static::getContainer()->get(AutoAssignmentPlanner::class);
    }

    private function availability(): AvailabilityService
    {
        return static::getContainer()->get(AvailabilityService::class);
    }

    private VolunteerType $type;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = new Department('Ops '.bin2hex(random_bytes(3)), 'ops-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->dept);
        $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($this->type);
        $this->em->flush();
    }

    private function member(string $name): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);
        $m = new UserVolunteerType($u, $this->type);
        $m->setConfirmedBy($u);
        $this->em->persist($m);
        $this->em->flush();

        return $u;
    }

    private function shift(string $start, string $end, int $needed): Shift
    {
        $shift = (new Shift())->setTitle('S '.bin2hex(random_bytes(2)))
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($this->dept)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($this->type, $needed);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();

        return $shift;
    }

    public function testProposalDoesNotPublishAssignments(): void
    {
        $this->member('alice');
        $this->member('bob');
        $shift = $this->shift('2026-06-01 10:00', '2026-06-01 12:00', 2);

        $proposal = $this->planner()->propose($this->dept);

        self::assertSame(ProposalStatus::DRAFT, $proposal->getStatus());
        self::assertGreaterThan(0, $proposal->getAssignments()->count());
        self::assertSame(0, $this->em->getRepository(ShiftEntry::class)->count(['shift' => $shift->getId()]), 'no ShiftEntry is created by a proposal');
    }

    public function testCapacityIsRespected(): void
    {
        $this->member('a');
        $this->member('b');
        $this->member('c');
        $shift = $this->shift('2026-06-01 10:00', '2026-06-01 12:00', 1);

        $proposal = $this->planner()->propose($this->dept);
        $forShift = array_filter($proposal->getAssignments()->toArray(), static fn ($a) => $a->getShift()->getId() === $shift->getId());
        self::assertCount(1, $forShift, 'only the open capacity is proposed');
    }

    public function testUnavailableUserIsNotProposed(): void
    {
        $available = $this->member('willing');
        $unavailable = $this->member('busy');
        $this->availability()->submit($unavailable, [
            ['start' => new \DateTimeImmutable('2026-06-01 09:00'), 'end' => new \DateTimeImmutable('2026-06-01 13:00'), 'value' => AvailabilityValue::UNAVAILABLE],
        ], null);
        $this->shift('2026-06-01 10:00', '2026-06-01 12:00', 1);

        $proposal = $this->planner()->propose($this->dept);
        $users = array_map(static fn ($a) => $a->getUser()->getId(), $proposal->getAssignments()->toArray());
        self::assertNotContains($unavailable->getId(), $users, 'an Unavailable user is never proposed');
    }

    public function testApplyPublishesAssignments(): void
    {
        $this->member('alice');
        $shift = $this->shift('2026-06-01 10:00', '2026-06-01 12:00', 1);
        $proposal = $this->planner()->propose($this->dept);

        $applied = $this->planner()->apply($proposal);
        self::assertGreaterThan(0, $applied);
        self::assertSame(ProposalStatus::APPLIED, $proposal->getStatus());
        self::assertSame(1, $this->em->getRepository(ShiftEntry::class)->count(['shift' => $shift->getId()]));
    }
}
