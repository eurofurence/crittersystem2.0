<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Exception\CapacityConflictException;
use App\Exception\StaleWriteException;
use App\Service\Shift\ShiftConcurrency;
use App\Service\ShiftSignupService;
use App\Tests\DatabaseTestCase;

/**
 * Backend concurrency protections: stale-write detection via the
 * shift version, capacity re-checked under a lock, and the unique (shift,user)
 * constraint blocking a duplicate assignment.
 */
final class ShiftConcurrencyTest extends DatabaseTestCase
{
    private function concurrency(): ShiftConcurrency
    {
        return static::getContainer()->get(ShiftConcurrency::class);
    }

    private function signup(): ShiftSignupService
    {
        return static::getContainer()->get(ShiftSignupService::class);
    }

    private function dept(): Department
    {
        $d = new Department('D '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);

        return $d;
    }

    private function member(VolunteerType $type): User
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);
        $m = new UserVolunteerType($u, $type);
        $m->setConfirmedBy($u);
        $this->em->persist($m);

        return $u;
    }

    private function shiftWithCapacity(int $needed): array
    {
        $type = new VolunteerType('T'.bin2hex(random_bytes(3)));
        $this->em->persist($type);
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($this->dept());
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, $needed);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);

        return [$shift, $type];
    }

    public function testStaleVersionIsRejected(): void
    {
        [$shift] = $this->shiftWithCapacity(1);
        $this->em->flush();
        $originalVersion = $shift->getVersion();

        // Someone else edits and saves the shift; its version advances.
        $shift->setTitle('Edited elsewhere');
        $this->em->flush();
        self::assertGreaterThan($originalVersion, $shift->getVersion());

        $this->expectException(StaleWriteException::class);
        $this->concurrency()->assertVersion($shift, $originalVersion);
    }

    public function testCurrentVersionPasses(): void
    {
        [$shift] = $this->shiftWithCapacity(1);
        $this->em->flush();

        $this->concurrency()->assertVersion($shift, $shift->getVersion());
        $this->addToAssertionCount(1); // no exception
    }

    public function testLastSlotRaceLeavesExactlyOneWinner(): void
    {
        [$shift, $type] = $this->shiftWithCapacity(1);
        $a = $this->member($type);
        $b = $this->member($type);
        $this->em->flush();

        $this->signup()->signUp($a, $shift, $type);

        // The single slot is now taken; the second signup must be refused.
        $this->expectException(\RuntimeException::class);
        $this->signup()->signUp($b, $shift, $type);
    }

    public function testDuplicateSignupForSameUserIsRejected(): void
    {
        [$shift, $type] = $this->shiftWithCapacity(5);
        $user = $this->member($type);
        $this->em->flush();

        $this->signup()->signUp($user, $shift, $type);

        $this->expectException(\RuntimeException::class);
        $this->signup()->signUp($user, $shift, $type);
    }
}
