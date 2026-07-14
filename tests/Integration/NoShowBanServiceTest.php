<?php

namespace App\Tests\Integration;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Gdpr\BanChecker;
use App\Service\NoShowBanService;
use App\Tests\DatabaseTestCase;

final class NoShowBanServiceTest extends DatabaseTestCase
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

    private function service(): NoShowBanService
    {
        return static::getContainer()->get(NoShowBanService::class);
    }

    private function bans(): BanChecker
    {
        return static::getContainer()->get(BanChecker::class);
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function addNoShow(User $user, string $start): void
    {
        $type = new VolunteerType('Helpers-'.bin2hex(random_bytes(4)));
        $this->em->persist($type);
        $shift = (new Shift())
            ->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt((new \DateTimeImmutable($start))->modify('+2 hours'))
            ->setDepartment($this->department());
        $this->em->persist($shift);
        $entry = new ShiftEntry($shift, $type, $user);
        $entry->setNoshow(true);
        $this->em->persist($entry);
        $this->em->flush();
    }

    public function testBelowThresholdDoesNotBan(): void
    {
        $user = $this->makeUser('one');
        $this->addNoShow($user, '-3 days');

        self::assertFalse($this->service()->evaluate($user));
        self::assertFalse($this->bans()->isUserBanned($user));
    }

    public function testReachingThresholdBans(): void
    {
        $user = $this->makeUser('two');
        $this->addNoShow($user, '-3 days');
        $this->addNoShow($user, '-2 days');

        self::assertTrue($this->service()->evaluate($user));
        self::assertTrue($this->bans()->isUserBanned($user));
    }

    public function testBaselineExcludesOlderNoShows(): void
    {
        $user = $this->makeUser('three');
        $this->addNoShow($user, '-5 days');
        $this->addNoShow($user, '-4 days');
        // Baseline after those no-shows: they no longer count.
        $user->setNoShowBaselineAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        self::assertSame(0, $this->service()->noShowCount($user));
        self::assertFalse($this->service()->evaluate($user));
    }

    public function testLiftAndResetClearsBanAndCounter(): void
    {
        $user = $this->makeUser('four');
        $this->addNoShow($user, '-3 days');
        $this->addNoShow($user, '-2 days');
        self::assertTrue($this->service()->evaluate($user));
        self::assertTrue($this->bans()->isUserBanned($user));

        $this->service()->liftAndReset($user, 'appeal accepted');

        self::assertFalse($this->bans()->isUserBanned($user));
        self::assertNotNull($user->getNoShowBaselineAt());
        // The old no-shows predate the new baseline, so the counter is back to 0.
        self::assertSame(0, $this->service()->noShowCount($user));
    }
}
