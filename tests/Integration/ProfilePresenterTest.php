<?php

namespace App\Tests\Integration;

use App\Entity\GoodieCategory;
use App\Entity\GoodieItem;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Entity\Worklog;
use App\Service\ProfilePresenter;
use App\Tests\DatabaseTestCase;

final class ProfilePresenterTest extends DatabaseTestCase
{
    private ?\App\Entity\Department $dept = null;

    private function department(): \App\Entity\Department
    {
        if ($this->dept === null) {
            $this->dept = new \App\Entity\Department('Dept '.bin2hex(random_bytes(3)), 'dept-'.bin2hex(random_bytes(3)));
            $this->em->persist($this->dept);
        }

        return $this->dept;
    }

    private function presenter(): ProfilePresenter
    {
        return static::getContainer()->get(ProfilePresenter::class);
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    public function testWorkHistoryMergesShiftsAndWorklogs(): void
    {
        $user = $this->makeUser('hank');
        $manager = $this->makeUser('mgr');
        $type = new VolunteerType('Logistics');
        $this->em->persist($type);

        $shift = (new Shift())
            ->setTitle('Past shift')
            ->setStartsAt(new \DateTimeImmutable('-2 days 10:00'))
            ->setEndsAt(new \DateTimeImmutable('-2 days 14:00'))
            ->setDepartment($this->department());
        $this->em->persist($shift);
        $this->em->persist(new ShiftEntry($shift, $type, $user));

        $worklog = (new Worklog($user))->setHours(3.0)->setComment('Extra help')->setCreator($manager)
            ->setWorkedAt(new \DateTimeImmutable('-1 day 09:00'));
        $this->em->persist($worklog);

        $this->em->flush();

        $rows = $this->presenter()->workHistory($user);
        self::assertCount(2, $rows);

        $kinds = array_column($rows, 'kind');
        self::assertContains('shift', $kinds);
        self::assertContains('worklog', $kinds);

        // Chronological: the shift (-2 days) precedes the worklog (-1 day).
        self::assertSame('shift', $rows[0]['kind']);
        self::assertSame('done', $rows[0]['status']);
        self::assertSame('mgr', $rows[1]['creator']);
    }

    public function testMemberships(): void
    {
        $user = $this->makeUser('mia');
        $type = new VolunteerType('Stage');
        $this->em->persist($type);
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $this->em->flush();

        $rows = $this->presenter()->memberships($user);
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['confirmed']);
        self::assertSame('Stage', $rows[0]['type']->getName());
    }

    public function testGoodiesGroupedByTier(): void
    {
        $user = $this->makeUser('gwen');
        $category = new GoodieCategory('Swag');
        $this->em->persist($category);
        $item = (new GoodieItem($category, 'T-Shirt'))->setRequiredHours(0.0)->setIsActive(true);
        $this->em->persist($item);
        $this->em->flush();

        $goodies = $this->presenter()->goodies($user);
        // Zero required hours => immediately claimable.
        self::assertNotEmpty($goodies['eligible']);
        self::assertSame('T-Shirt', $goodies['eligible'][0]['item']->getName());
    }
}
