<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Entity\Worklog;
use App\Repository\UserHoursCacheRepository;
use App\Service\HoursCacheService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cached hours keep themselves current.
 *
 * The cache had no invalidation at all: a worklog or a no-show left the row untouched, and a shift
 * ending writes nothing anywhere, so totals only moved when a day-long lifetime expired or somebody
 * pressed refresh. Both halves are covered here, because they fail differently: one is a write that
 * something must notice, the other is the absence of a write.
 */
final class HoursRecalculationTest extends DatabaseTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function caches(): UserHoursCacheRepository
    {
        return static::getContainer()->get(UserHoursCacheRepository::class);
    }

    private function hours(): HoursCacheService
    {
        return static::getContainer()->get(HoursCacheService::class);
    }

    private function volunteer(): User
    {
        $user = new User();
        $user->setName('vol-'.bin2hex(random_bytes(3)))
            ->setEmail('vol-'.bin2hex(random_bytes(3)).'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** A shift that has already finished, which is what makes hours countable. */
    private function completedShiftFor(User $user): void
    {
        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('-4 hours'))->setEndsAt(new \DateTimeImmutable('-2 hours'));
        $this->scenario->signUp($user, $shift);
        $this->em->flush();
    }

    /**
     * The write half. Nothing in the controller marks the cache: the listener on the flush does, so
     * a future write path cannot forget it.
     */
    public function testAWorklogWriteMarksTheCacheDirty(): void
    {
        $user = $this->volunteer();
        $cache = $this->hours()->recalculate($user);
        self::assertFalse($cache->isDirty());

        $worklog = (new Worklog($user))->setHours(3.5);
        $this->em->persist($worklog);
        $this->em->flush();

        $this->em->clear();
        self::assertTrue(
            $this->caches()->findOneByUser($this->em->getRepository(User::class)->find($user->getId()))->isDirty(),
        );
    }

    public function testASignUpMarksTheCacheDirty(): void
    {
        $user = $this->volunteer();
        $this->hours()->recalculate($user);

        $this->completedShiftFor($user);

        $this->em->clear();
        self::assertTrue(
            $this->caches()->findOneByUser($this->em->getRepository(User::class)->find($user->getId()))->isDirty(),
        );
    }

    /** A dirty row is not fresh, however recently it was calculated. */
    public function testADirtyRowIsRecalculatedOnRead(): void
    {
        $user = $this->volunteer();
        $cache = $this->hours()->recalculate($user);
        $cache->setDirty(true);
        $this->em->flush();

        self::assertFalse($this->hours()->get($user)->isDirty());
    }

    /**
     * The clock half. Nobody writes when a shift ends, so the sweep finds these by comparing the
     * shift's end against the moment the row was calculated. The row is aged deliberately to the
     * state a job that ran before the shift finished would leave behind.
     */
    public function testAUserWhoseShiftEndedAfterTheirLastCalculationIsSelected(): void
    {
        $user = $this->volunteer();
        $this->completedShiftFor($user);

        $cache = $this->hours()->recalculate($user);
        $cache->setDirty(false)->setLastCalculatedAt(new \DateTimeImmutable('-6 hours'));
        $this->em->flush();

        self::assertContains($user->getId(), $this->caches()->findUserIdsNeedingRecalculation());
    }

    public function testAnUpToDateUserIsNotSelected(): void
    {
        $user = $this->volunteer();
        $this->completedShiftFor($user);
        $this->hours()->recalculate($user);

        self::assertNotContains($user->getId(), $this->caches()->findUserIdsNeedingRecalculation());
    }

    /** Somebody with nothing to count is not worth a row of zeroes. */
    public function testAUserWithNoHoursIsNotSelected(): void
    {
        $user = $this->volunteer();

        self::assertNotContains($user->getId(), $this->caches()->findUserIdsNeedingRecalculation());
    }

    public function testAForcedRebuildIncludesAnUpToDateUser(): void
    {
        $user = $this->volunteer();
        $this->completedShiftFor($user);
        $this->hours()->recalculate($user);

        self::assertContains($user->getId(), $this->caches()->findAllUserIdsWithHours());
    }

    /** The sweep is what actually corrects the total, not merely the selection. */
    public function testRecalculationCreditsTheCompletedShift(): void
    {
        $user = $this->volunteer();
        $cache = $this->hours()->recalculate($user);
        self::assertSame(0.0, $cache->getTotalHours());

        $this->completedShiftFor($user);

        self::assertGreaterThan(0.0, $this->hours()->recalculate($user)->getTotalHours());
    }
}
