<?php

namespace App\Tests\Integration;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Entity\Worklog;
use App\Service\HoursCacheService;
use App\Tests\DatabaseTestCase;

final class HoursCacheServiceTest extends DatabaseTestCase
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

    private function service(): HoursCacheService
    {
        return static::getContainer()->get(HoursCacheService::class);
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function entry(User $user, VolunteerType $type, string $start, string $end, bool $noshow = false): void
    {
        $shift = (new Shift())->setTitle('S')->setStartsAt(new \DateTimeImmutable($start))->setEndsAt(new \DateTimeImmutable($end))->setDepartment($this->department());
        $this->em->persist($shift);
        $e = new ShiftEntry($shift, $type, $user);
        $e->setNoshow($noshow);
        $this->em->persist($e);
    }

    public function testBreakdownFromCompletedShiftsAndWorklogs(): void
    {
        $type = new VolunteerType('Logistics');
        $this->em->persist($type);
        $user = $this->user('hank');

        $this->entry($user, $type, '-3 day 10:00', '-3 day 14:00');          // day, 4h
        $this->entry($user, $type, '-2 day 22:00', '-1 day 06:00');          // night, 8h -> x2 = 16
        $this->entry($user, $type, '-4 day 10:00', '-4 day 14:00', true);    // no-show, 4h -> -8
        $this->entry($user, $type, '+2 day 10:00', '+2 day 14:00');          // future -> ignored

        $worklog = new Worklog($user);
        $worklog->setHours(5.0);
        $this->em->persist($worklog);
        $this->em->flush();

        $cache = $this->service()->recalculate($user);

        self::assertSame(4.0, $cache->getDayShiftsHours());
        self::assertSame(16.0, $cache->getNightShiftsHours());
        self::assertSame(-8.0, $cache->getNoshowPenaltyHours());
        self::assertSame(5.0, $cache->getWorklogHours());
        self::assertSame(17.0, $cache->getTotalHours()); // 4 + 16 - 8 + 5
        self::assertSame(2, $cache->getCompletedShiftsCount());
        self::assertSame(1, $cache->getNightShiftsCount());
        self::assertSame(1, $cache->getNoshowShiftsCount());
    }

    public function testGetReusesFreshCacheButRefreshRecalculates(): void
    {
        $user = $this->user('iris');
        $worklog = new Worklog($user);
        $worklog->setHours(3.0);
        $this->em->persist($worklog);
        $this->em->flush();

        $first = $this->service()->get($user);
        self::assertSame(3.0, $first->getTotalHours());

        // Add more hours; a plain get() should return the still-fresh cache.
        $extra = new Worklog($user);
        $extra->setHours(4.0);
        $this->em->persist($extra);
        $this->em->flush();

        self::assertSame(3.0, $this->service()->get($user)->getTotalHours());
        self::assertSame(7.0, $this->service()->get($user, true)->getTotalHours());
    }
}
