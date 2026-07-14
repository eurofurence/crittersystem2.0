<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\Shift\PositionService;
use App\Tests\DatabaseTestCase;

/**
 * Advanced Matrix Planner positions model: one user holds a
 * single shift entry while occupying multiple named positions, and a position's
 * capacity is enforced.
 */
final class PositionServiceTest extends DatabaseTestCase
{
    private function service(): PositionService
    {
        return static::getContainer()->get(PositionService::class);
    }

    private function dept(): Department
    {
        $d = new Department('Stage', 'stage-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);

        return $d;
    }

    private function shift(Department $dept): Shift
    {
        $shift = (new Shift())
            ->setTitle('Show')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 4 hours'))
            ->setDepartment($dept);
        $this->em->persist($shift);

        return $shift;
    }

    private function entry(Shift $shift, string $name): ShiftEntry
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $type = new VolunteerType('Crew '.$name);
        $this->em->persist($type);
        $entry = new ShiftEntry($shift, $type, $user);
        $this->em->persist($entry);

        return $entry;
    }

    public function testUserHoldsMultiplePositionsWithOneShiftEntry(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $shift = $this->shift($dept);
        $this->em->flush();

        $group = $svc->createGroup($dept, 'Light');
        $foh = $svc->createPosition($group, 'FOH');
        $spot = $svc->createPosition($group, 'Spot');

        $entry = $this->entry($shift, 'alex');
        $this->em->flush();

        $spFoh = $svc->enablePosition($shift, $foh);
        $spSpot = $svc->enablePosition($shift, $spot);

        $svc->assign($spFoh, $entry);
        $svc->assign($spSpot, $entry);
        $this->em->clear();

        $reloaded = $this->em->getRepository(ShiftEntry::class)->find($entry->getId());
        self::assertCount(2, $reloaded->getPositionAssignments(), 'both positions attach to one entry');

        // Still exactly one shift entry for this user/shift.
        $entries = $this->em->getRepository(ShiftEntry::class)->count([
            'shift' => $shift->getId(),
            'user' => $reloaded->getUser()->getId(),
        ]);
        self::assertSame(1, $entries);
    }

    public function testCapacityIsEnforced(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $shift = $this->shift($dept);
        $this->em->flush();

        $group = $svc->createGroup($dept, 'Stage');
        $solo = $svc->createPosition($group, 'Backline', 1);
        $sp = $svc->enablePosition($shift, $solo);

        $a = $this->entry($shift, 'first');
        $b = $this->entry($shift, 'second');
        $this->em->flush();

        $svc->assign($sp, $a);

        $this->expectException(\RuntimeException::class);
        $svc->assign($sp, $b);
    }

    public function testEnablePositionIsIdempotent(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $shift = $this->shift($dept);
        $this->em->flush();

        $group = $svc->createGroup($dept, 'Sound');
        $pos = $svc->createPosition($group, 'Mixer');

        $first = $svc->enablePosition($shift, $pos);
        $second = $svc->enablePosition($shift, $pos);
        self::assertSame($first->getId(), $second->getId());
    }

    public function testCellStateReflectsRequiredAndFilled(): void
    {
        $svc = $this->service();
        $dept = $this->dept();
        $shift = $this->shift($dept);
        $this->em->flush();

        $group = $svc->createGroup($dept, 'Grip');
        $pos = $svc->createPosition($group, 'Hand #1', 2);

        $required = $svc->enablePosition($shift, $pos, true);
        self::assertSame('open', $required->cellState());

        $required->setRequired(false);
        self::assertSame('not_required', $required->cellState());

        $required->setRequired(true);
        $entry = $this->entry($shift, 'grip');
        $this->em->flush();
        $svc->assign($required, $entry);
        self::assertSame('filled', $required->cellState());
    }
}
