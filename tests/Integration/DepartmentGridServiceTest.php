<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftEntryState;
use App\Service\Shift\DepartmentGridService;
use App\Tests\DatabaseTestCase;

/**
 * Department shift grid fill status: each row reports needed vs
 * assigned and the derived fill state, plus applications and open positions.
 */
final class DepartmentGridServiceTest extends DatabaseTestCase
{
    private function service(): DepartmentGridService
    {
        return static::getContainer()->get(DepartmentGridService::class);
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

    private function shift(int $needed): Shift
    {
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($this->dept);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($this->type, $needed);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();

        return $shift;
    }

    private function assign(Shift $shift, ShiftEntryState $state): void
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);
        $entry = new ShiftEntry($shift, $this->type, $u);
        $entry->setState($state);
        $this->em->persist($entry);
        $this->em->flush();
    }

    public function testOpenFillStatus(): void
    {
        $shift = $this->shift(2);
        $this->assign($shift, ShiftEntryState::ASSIGNMENT);
        $this->em->refresh($shift);

        $row = $this->service()->row($shift);
        self::assertSame(2, $row['needed']);
        self::assertSame(1, $row['assigned']);
        self::assertSame('open', $row['fillState']);
    }

    public function testFullFillStatus(): void
    {
        $shift = $this->shift(1);
        $this->assign($shift, ShiftEntryState::ASSIGNMENT);
        $this->em->refresh($shift);

        self::assertSame('full', $this->service()->row($shift)['fillState']);
    }

    public function testApplicationsAndAssignmentsCountedSeparately(): void
    {
        $shift = $this->shift(3);
        $this->assign($shift, ShiftEntryState::ASSIGNMENT);
        $this->assign($shift, ShiftEntryState::APPLICATION);
        $this->em->refresh($shift);

        $row = $this->service()->row($shift);
        self::assertCount(1, $row['assignedUsers'], 'assigned-users list shows only confirmed assignments');
        self::assertSame(1, $row['applications']);
        // Both a confirmed assignment and a pending application occupy a slot.
        self::assertSame(2, $row['assigned']);
        self::assertSame('open', $row['fillState']);
    }

    public function testNoRequirementIsNeutralFill(): void
    {
        $shift = $this->shift(0);
        self::assertSame('none', $this->service()->row($shift)['fillState']);
    }
}
