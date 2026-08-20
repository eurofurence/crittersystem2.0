<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Repository\UserHoursCacheRepository;
use App\Service\HoursCacheService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The scheduled sweep that keeps cached hours current.
 *
 * It has to be safe to run every five minutes forever, so a run with nothing to do must cost
 * nothing, and a run that is missed must be made up by the next one rather than lost.
 */
final class RecalculateHoursCommandTest extends DatabaseTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function sweep(array $options = []): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:hours:recalculate'));
        $tester->execute($options);

        return $tester;
    }

    private function volunteerWithCompletedShift(): User
    {
        $user = new User();
        $user->setName('vol-'.bin2hex(random_bytes(3)))
            ->setEmail('vol-'.bin2hex(random_bytes(3)).'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('-4 hours'))->setEndsAt(new \DateTimeImmutable('-2 hours'));
        $this->scenario->signUp($user, $shift);
        $this->em->flush();

        return $user;
    }

    /** Under test the message is routed sync, so this both queues and performs the work. */
    public function testTheSweepCreditsAVolunteerWhoseShiftHasEnded(): void
    {
        $user = $this->volunteerWithCompletedShift();

        $this->sweep();

        $this->em->clear();
        $cache = static::getContainer()->get(UserHoursCacheRepository::class)
            ->findOneByUser($this->em->getRepository(User::class)->find($user->getId()));

        self::assertNotNull($cache);
        self::assertGreaterThan(0.0, $cache->getTotalHours());
        self::assertFalse($cache->isDirty());
    }

    public function testSyncModeDoesTheWorkInProcess(): void
    {
        $user = $this->volunteerWithCompletedShift();

        $tester = $this->sweep(['--sync' => true]);

        self::assertStringContainsString('Recalculated', $tester->getDisplay());
        self::assertGreaterThan(
            0.0,
            static::getContainer()->get(HoursCacheService::class)->get(
                $this->em->getRepository(User::class)->find($user->getId())
            )->getTotalHours(),
        );
    }

    /** A run with nothing to do is the common case and must be a no-op, not an error. */
    public function testASecondRunFindsNothingToDo(): void
    {
        $this->volunteerWithCompletedShift();
        $this->sweep();

        $tester = $this->sweep();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('already current', $tester->getDisplay());
    }

    public function testAllRebuildsEvenWhenNothingChanged(): void
    {
        $this->volunteerWithCompletedShift();
        $this->sweep();

        $tester = $this->sweep(['--all' => true, '--sync' => true]);

        self::assertStringContainsString('Recalculated 1 user', $tester->getDisplay());
    }
}
