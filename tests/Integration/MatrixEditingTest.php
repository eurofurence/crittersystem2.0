<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\Shift\PositionService;
use App\Tests\DatabaseTestCase;

/**
 * Advanced Matrix Planner structure editing: reorder,
 * copy structure between shifts, assignment, and the assignment-resolution guard
 * when disabling a position that still holds people.
 */
final class MatrixEditingTest extends DatabaseTestCase
{
    private function service(): PositionService
    {
        return static::getContainer()->get(PositionService::class);
    }

    private function dept(): Department
    {
        $d = new Department('Stage '.bin2hex(random_bytes(3)), 'st-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    private function shift(Department $dept, string $title = 'Show'): Shift
    {
        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable('+1 day 20:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 23:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    private function user(string $name): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testReorderPositions(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $group = $svc->createGroup($dept, 'Light');
        $a = $svc->createPosition($group, 'A');
        $b = $svc->createPosition($group, 'B');
        $c = $svc->createPosition($group, 'C');

        $svc->reorderPositions($group, [$c->getId(), $a->getId(), $b->getId()]);

        self::assertSame(1, $c->getDisplayOrder());
        self::assertSame(2, $a->getDisplayOrder());
        self::assertSame(3, $b->getDisplayOrder());
    }

    public function testCopyStructureReplicatesPositionsNotAssignments(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $group = $svc->createGroup($dept, 'Stage');
        $foh = $svc->createPosition($group, 'FOH');
        $spot = $svc->createPosition($group, 'Spot');

        $source = $this->shift($dept, 'Source');
        $spFoh = $svc->enablePosition($source, $foh, true);
        $spFoh->setNote('bring gloves');
        $svc->enablePosition($source, $spot, false);
        // Put an assignment on the source that must NOT be copied.
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $entry = new \App\Entity\ShiftEntry($source, $type, $this->user('dana'));
        $this->em->persist($entry);
        $this->em->flush();
        $svc->assign($spFoh, $entry);

        $target = $this->shift($dept, 'Target');
        $svc->copyStructure($source, $target);
        $this->em->clear();

        $reloaded = $this->em->getRepository(Shift::class)->find($target->getId());
        self::assertCount(2, $reloaded->getShiftPositions(), 'both positions copied');
        $byName = [];
        foreach ($reloaded->getShiftPositions() as $sp) {
            $byName[$sp->getNamedPosition()->getName()] = $sp;
            self::assertCount(0, $sp->getAssignments(), 'assignments are not copied');
        }
        self::assertTrue($byName['FOH']->isRequired());
        self::assertSame('bring gloves', $byName['FOH']->getNote());
        self::assertFalse($byName['Spot']->isRequired());
    }

    public function testDisablePositionWithAssignmentsRequiresForce(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $group = $svc->createGroup($dept, 'Sound');
        $pos = $svc->createPosition($group, 'Mixer');
        $shift = $this->shift($dept);
        $sp = $svc->enablePosition($shift, $pos);

        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $entry = new \App\Entity\ShiftEntry($shift, $type, $this->user('ren'));
        $this->em->persist($entry);
        $this->em->flush();
        $svc->assign($sp, $entry);

        try {
            $svc->disablePosition($sp);
            self::fail('expected a resolution error');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }

        // With force it is removed.
        $svc->disablePosition($sp, true);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Shift::class)->find($shift->getId())->getShiftPositions());
    }

    public function testAssignUserCreatesEntryFromRequiredType(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $group = $svc->createGroup($dept, 'Grip');
        $pos = $svc->createPosition($group, 'Hand #1');
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $pos->addRequiredVolunteerType($type);
        $this->em->flush();

        $shift = $this->shift($dept);
        $sp = $svc->enablePosition($shift, $pos);
        $user = $this->user('kim');

        $svc->assignUser($sp, $user);
        $this->em->clear();

        $reloaded = $this->em->getRepository(Shift::class)->find($shift->getId());
        self::assertCount(1, $reloaded->getEntries(), 'one entry created for the user');
        self::assertSame('filled', $reloaded->getShiftPositions()->first()->cellState());
    }
}
