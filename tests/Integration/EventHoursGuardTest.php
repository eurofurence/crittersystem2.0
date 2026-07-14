<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\Assignment\EventHoursGuard;
use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;

/**
 * Recommended event-hours threshold: planned hours are reported and
 * warnings fire when a shift would take a user past the recommendation.
 */
final class EventHoursGuardTest extends DatabaseTestCase
{
    private function guard(): EventHoursGuard
    {
        return static::getContainer()->get(EventHoursGuard::class);
    }

    private function setMax(int $max): void
    {
        static::getContainer()->get(EventConfigStore::class)->set(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, $max);
    }

    private function user(): User
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function assign(User $user, string $start, string $end): void
    {
        $dept = new Department('D '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $type = new VolunteerType('T'.bin2hex(random_bytes(3)));
        $this->em->persist($type);
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $this->em->persist(new ShiftEntry($shift, $type, $user));
        $this->em->flush();
    }

    public function testPlannedHoursAndOverBy(): void
    {
        $this->setMax(10);
        $user = $this->user();
        // Daytime shifts (no night multiplier): 6h + 6h = 12 planned.
        $this->assign($user, '2026-06-01 10:00', '2026-06-01 16:00');
        $this->assign($user, '2026-06-02 10:00', '2026-06-02 16:00');

        self::assertSame(12.0, $this->guard()->plannedHours($user));
        self::assertTrue($this->guard()->isOver($user));
        self::assertSame(2.0, $this->guard()->overBy($user));
    }

    public function testWithinThresholdIsNotOver(): void
    {
        $this->setMax(20);
        $user = $this->user();
        $this->assign($user, '2026-06-01 10:00', '2026-06-01 14:00'); // 4h

        self::assertFalse($this->guard()->isOver($user));
        self::assertSame(0.0, $this->guard()->overBy($user));
    }

    public function testWouldExceedFlagsAShiftThatCrossesTheThreshold(): void
    {
        $this->setMax(10);
        $user = $this->user();
        $this->assign($user, '2026-06-01 10:00', '2026-06-01 18:00'); // 8h planned

        $dept = new Department('D2 '.bin2hex(random_bytes(3)), 'd2-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $candidate = (new Shift())->setTitle('Extra')
            ->setStartsAt(new \DateTimeImmutable('2026-06-03 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-03 14:00')) // +4h -> 12 > 10
            ->setDepartment($dept);
        $this->em->persist($candidate);
        $this->em->flush();

        self::assertTrue($this->guard()->wouldExceed($user, $candidate));
    }
}
