<?php

namespace App\Tests\Integration;

use App\Entity\GoodieCategory;
use App\Entity\GoodieDistribution;
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

    /** The merged history runs chronologically: the shift two days back precedes yesterday's worklog. */
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

    /**
     * The tracker reads one flat row list and styles each entry from its own tier, so the
     * presenter hands back the evaluation unchanged rather than three pre-grouped buckets.
     */
    public function testGoodiesReturnsCreditedHoursAndOneTieredRowPerItem(): void
    {
        $user = $this->makeUser('gwen');
        $category = new GoodieCategory('Swag');
        $this->em->persist($category);
        $item = (new GoodieItem($category, 'T-Shirt'))->setRequiredHours(0.0)->setIsActive(true);
        $this->em->persist($item);
        $this->em->flush();

        $goodies = $this->presenter()->goodies($user);

        self::assertSame(['hours', 'rows'], array_keys($goodies));
        self::assertSame(0.0, $goodies['hours']);
        self::assertCount(1, $goodies['rows']);

        $row = $goodies['rows'][0];
        self::assertSame('T-Shirt', $row['item']->getName());
        self::assertSame('eligible', $row['tier'], 'zero required hours is claimable straight away');
        self::assertSame(0.0, $row['gap']);
        self::assertSame(0, $row['claimed']);
    }

    /**
     * The timeline marks the first pending row as the next target and locks the ones after it,
     * so rows have to arrive cheapest-first within a category or the marker lands on the
     * wrong goodie.
     */
    public function testGoodiesRowsRunCheapestFirstWithinACategory(): void
    {
        $user = $this->makeUser('gil');
        $category = new GoodieCategory('Swag');
        $this->em->persist($category);
        foreach (['Hoodie' => 20.0, 'Badge' => 0.0, 'Mug' => 5.0] as $name => $hours) {
            $this->em->persist((new GoodieItem($category, $name))->setRequiredHours($hours)->setIsActive(true));
        }
        $this->em->flush();

        $rows = $this->presenter()->goodies($user)['rows'];

        self::assertSame(['Badge', 'Mug', 'Hoodie'], array_map(fn (array $r) => $r['item']->getName(), $rows));
        self::assertSame(['eligible', 'pending', 'pending'], array_column($rows, 'tier'));
        self::assertSame([0.0, 5.0, 20.0], array_column($rows, 'gap'));
    }

    public function testAnItemAtItsPerPersonLimitIsReportedAsClaimed(): void
    {
        $user = $this->makeUser('gus');
        $category = new GoodieCategory('Swag');
        $this->em->persist($category);
        $item = (new GoodieItem($category, 'Pin'))->setRequiredHours(0.0)->setIsActive(true)->setMaxPerPerson(1);
        $this->em->persist($item);
        $this->em->persist(new GoodieDistribution($user, $item));
        $this->em->flush();

        $row = $this->presenter()->goodies($user)['rows'][0];

        self::assertSame('claimed', $row['tier']);
        self::assertSame(1, $row['claimed']);
        self::assertSame(0, $row['remaining']);
    }

    public function testAnInactiveItemIsNotOfferedAtAll(): void
    {
        $user = $this->makeUser('gwyn');
        $category = new GoodieCategory('Swag');
        $this->em->persist($category);
        $this->em->persist((new GoodieItem($category, 'Retired Tee'))->setRequiredHours(0.0)->setIsActive(false));
        $this->em->flush();

        self::assertSame([], $this->presenter()->goodies($user)['rows']);
    }
}
