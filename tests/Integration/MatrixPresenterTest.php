<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\NamedPosition;
use App\Entity\PositionGroup;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftPosition;
use App\Entity\ShiftPositionAssignment;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\Shift\MatrixPresenter;
use App\Tests\DatabaseTestCase;

/**
 * Advanced Matrix Planner view model: columns come from
 * department-configured Position Groups/Named Positions (no hard-coded labels)
 * and cell state maps the reference values structurally.
 */
final class MatrixPresenterTest extends DatabaseTestCase
{
    private function presenter(): MatrixPresenter
    {
        return static::getContainer()->get(MatrixPresenter::class);
    }

    private function dept(): Department
    {
        $d = new Department('Stage '.bin2hex(random_bytes(3)), 'st-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);

        return $d;
    }

    public function testColumnsComeFromDepartmentDataNotHardCoded(): void
    {
        $dept = $this->dept();
        // Labels chosen freely by the department — the presenter must echo them.
        $group = new PositionGroup($dept, 'Catering');
        $this->em->persist($group);
        $pos = new NamedPosition($group, 'Head Chef');
        $this->em->persist($pos);
        $this->em->flush();

        $matrix = $this->presenter()->buildMatrix($dept, [], new \DateTimeZone('UTC'));

        self::assertSame('Catering', $matrix['groups'][0]['name']);
        self::assertSame('Head Chef', $matrix['columns'][0]['name']);
    }

    public function testCellStatesMapReferenceValues(): void
    {
        $dept = $this->dept();
        $group = new PositionGroup($dept, 'Light');
        $this->em->persist($group);
        $filledPos = new NamedPosition($group, 'FOH');
        $openPos = new NamedPosition($group, 'Spot');
        $notReqPos = new NamedPosition($group, 'Laser');
        $this->em->persist($filledPos);
        $this->em->persist($openPos);
        $this->em->persist($notReqPos);

        $shift = (new Shift())->setTitle('Show')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 20:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 23:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);

        // filled: enabled + assigned
        $spFilled = new ShiftPosition($shift, $filledPos);
        $shift->addShiftPosition($spFilled);
        $this->em->persist($spFilled);
        $user = new User();
        $user->setName('Dana')->setEmail('dana@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $entry = new ShiftEntry($shift, $type, $user);
        $this->em->persist($entry);
        $this->em->persist(new ShiftPositionAssignment($entry, $spFilled));

        // open: enabled + required + unfilled
        $spOpen = (new ShiftPosition($shift, $openPos))->setRequired(true);
        $shift->addShiftPosition($spOpen);
        $this->em->persist($spOpen);

        // not_required: enabled + not required
        $spNot = (new ShiftPosition($shift, $notReqPos))->setRequired(false);
        $shift->addShiftPosition($spNot);
        $this->em->persist($spNot);

        $this->em->flush();

        $matrix = $this->presenter()->buildMatrix($dept, [$shift], new \DateTimeZone('UTC'));
        $cells = $matrix['rows'][0]['cells'];

        self::assertSame('filled', $cells[$filledPos->getId()]['state']);
        self::assertSame(['Dana'], $cells[$filledPos->getId()]['occupants']);
        self::assertSame('open', $cells[$openPos->getId()]['state']);
        self::assertSame('not_required', $cells[$notReqPos->getId()]['state']);
    }

    public function testDisabledPositionIsAnEmptyCell(): void
    {
        $dept = $this->dept();
        $group = new PositionGroup($dept, 'Stage');
        $this->em->persist($group);
        $enabled = new NamedPosition($group, 'Hand #1');
        $disabled = new NamedPosition($group, 'Hand #2');
        $this->em->persist($enabled);
        $this->em->persist($disabled);

        $shift = (new Shift())->setTitle('Build')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 08:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 12:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $sp = new ShiftPosition($shift, $enabled);
        $shift->addShiftPosition($sp);
        $this->em->persist($sp);
        $this->em->flush();

        $matrix = $this->presenter()->buildMatrix($dept, [$shift], new \DateTimeZone('UTC'));
        $cells = $matrix['rows'][0]['cells'];

        self::assertSame('open', $cells[$enabled->getId()]['state']);
        self::assertSame('empty', $cells[$disabled->getId()]['state'], 'a position not enabled on the shift is an empty cell');
    }
}
