<?php

namespace App\Tests\Integration;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\OperationalStatusService;
use App\Tests\DatabaseTestCase;

final class OperationalStatusServiceTest extends DatabaseTestCase
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

    private function service(): OperationalStatusService
    {
        return static::getContainer()->get(OperationalStatusService::class);
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function assignActiveShift(User $user, string $start, string $end): void
    {
        $type = new VolunteerType('Helpers');
        $this->em->persist($type);
        $shift = (new Shift())
            ->setTitle('Active')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($this->department());
        $this->em->persist($shift);
        $this->em->persist(new ShiftEntry($shift, $type, $user));
        $this->em->flush();
    }

    public function testDefaultIsNoShifts(): void
    {
        $user = $this->makeUser('nina');

        self::assertSame(OperationalStatusService::NO_SHIFTS, $this->service()->effectiveStatus($user));
    }

    public function testFreeToHelpWhileOverrideActive(): void
    {
        $user = $this->makeUser('fred');
        $this->service()->setFreeToHelp($user, 60);

        self::assertSame(OperationalStatusService::FREE_TO_HELP, $this->service()->effectiveStatus($user));

        $vm = $this->service()->viewModel($user);
        self::assertTrue($vm['freeToHelp']);
        self::assertNotNull($vm['expiresAt']);
    }

    /** A 30-minute override is spent 31 minutes on, and the status falls back to the automatic one. */
    public function testOverrideExpiresBackToAutomatic(): void
    {
        $user = $this->makeUser('otto');
        $this->service()->setFreeToHelp($user, 30);

        $later = (new \DateTimeImmutable())->modify('+31 minutes');
        self::assertSame(OperationalStatusService::NO_SHIFTS, $this->service()->effectiveStatus($user, $later));
    }

    public function testActiveShiftForcesNotAvailableEvenWithOverride(): void
    {
        $user = $this->makeUser('sam');
        $this->service()->setFreeToHelp($user, 120);
        $this->assignActiveShift($user, '-1 hour', '+1 hour');

        self::assertSame(OperationalStatusService::NOT_AVAILABLE, $this->service()->effectiveStatus($user));
    }

    public function testClearReturnsToAutomatic(): void
    {
        $user = $this->makeUser('cleo');
        $this->service()->setFreeToHelp($user, 60);
        self::assertSame(OperationalStatusService::FREE_TO_HELP, $this->service()->effectiveStatus($user));

        $this->service()->clear($user);
        self::assertSame(OperationalStatusService::NO_SHIFTS, $this->service()->effectiveStatus($user));
    }

    public function testUnsupportedDurationIsRejected(): void
    {
        $user = $this->makeUser('val');
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->setFreeToHelp($user, 45);
    }
}
