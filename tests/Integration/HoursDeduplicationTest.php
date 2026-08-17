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
use App\Service\HoursCalculator;
use App\Tests\DatabaseTestCase;

/**
 * Overlap deduplication of worked hours: overlapping
 * assignments never multiply rewarded time, and multiple positions in one shift
 * add no extra hours.
 */
final class HoursDeduplicationTest extends DatabaseTestCase
{
    private function calculator(): HoursCalculator
    {
        return static::getContainer()->get(HoursCalculator::class);
    }

    private function dept(): Department
    {
        $d = new Department('Dept', 'dept-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);

        return $d;
    }

    private function user(): User
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function type(): VolunteerType
    {
        $t = new VolunteerType('T'.bin2hex(random_bytes(3)));
        $this->em->persist($t);

        return $t;
    }

    /** @param array<int, array{0:string,1:string}> $intervals */
    private function entriesFor(User $user, array $intervals): array
    {
        $dept = $this->dept();
        $type = $this->type();
        $entries = [];
        foreach ($intervals as [$start, $end]) {
            $shift = (new Shift())->setTitle('S')
                ->setStartsAt(new \DateTimeImmutable($start))
                ->setEndsAt(new \DateTimeImmutable($end))
                ->setDepartment($dept);
            $this->em->persist($shift);
            $entry = new ShiftEntry($shift, $type, $user);
            $this->em->persist($entry);
            $entries[] = $entry;
        }
        $this->em->flush();

        return $entries;
    }

    /** Five entries over the same 10:00-11:00 daytime hour reward one hour, with no night multiplier. */
    public function testFiveIdenticalAssignmentsCountAsOneHour(): void
    {
        $user = $this->user();
        $entries = $this->entriesFor($user, array_fill(0, 5, ['2026-06-01 10:00', '2026-06-01 11:00']));
        self::assertSame(1.0, $this->calculator()->breakdown($entries)->total());
    }

    public function testTwentyOverlappingAssignmentsCountAsSixHours(): void
    {
        $user = $this->user();
        $entries = $this->entriesFor($user, array_fill(0, 20, ['2026-06-01 08:00', '2026-06-01 14:00']));
        self::assertSame(6.0, $this->calculator()->breakdown($entries)->total());
    }

    public function testTwoNonOverlappingIntervalsAddUp(): void
    {
        $user = $this->user();
        $entries = $this->entriesFor($user, [
            ['2026-06-01 10:00', '2026-06-01 11:00'],
            ['2026-06-01 12:00', '2026-06-01 13:00'],
        ]);
        self::assertSame(2.0, $this->calculator()->breakdown($entries)->total());
    }

    /** 10:00-12:00 and 11:00-13:00 union to 10:00-13:00, which is three hours. */
    public function testPartialOverlapCountsUnionOnce(): void
    {
        $user = $this->user();
        $entries = $this->entriesFor($user, [
            ['2026-06-01 10:00', '2026-06-01 12:00'],
            ['2026-06-01 11:00', '2026-06-01 13:00'],
        ]);
        self::assertSame(3.0, $this->calculator()->breakdown($entries)->total());
    }

    /** Hours come from the one entry per shift, so two positions on it still reward its four hours once. */
    public function testMultiplePositionsInOneShiftAddNoExtraHours(): void
    {
        $user = $this->user();
        $dept = $this->dept();
        $type = $this->type();
        $shift = (new Shift())->setTitle('Show')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 14:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $entry = new ShiftEntry($shift, $type, $user);
        $this->em->persist($entry);

        $group = new PositionGroup($dept, 'Stage');
        $this->em->persist($group);
        foreach (['Hand #1', 'Hand #2'] as $name) {
            $pos = new NamedPosition($group, $name);
            $this->em->persist($pos);
            $sp = new ShiftPosition($shift, $pos);
            $this->em->persist($sp);
            $this->em->persist(new ShiftPositionAssignment($entry, $sp));
        }
        $this->em->flush();

        self::assertSame(4.0, $this->calculator()->breakdown([$entry])->total());
    }

    /** 22:00-06:00 touches the 02:00-08:00 night window, so all 8 hours double to 16. */
    public function testNightMultiplierStillAppliesAfterDedup(): void
    {
        $user = $this->user();
        $entries = $this->entriesFor($user, [['2026-06-01 22:00', '2026-06-02 06:00']]);
        $breakdown = $this->calculator()->breakdown($entries);
        self::assertSame(16.0, $breakdown->nightHours);
        self::assertSame(16.0, $breakdown->total());
    }

    /** A 4-hour no-show carries a penalty of 4h times -2, and rewards no hours. */
    public function testNoShowPenaltyIsSeparateAdditiveTerm(): void
    {
        $user = $this->user();
        $entries = $this->entriesFor($user, [['2026-06-01 10:00', '2026-06-01 14:00']]);
        $entries[0]->setNoshow(true);
        $this->em->flush();

        $breakdown = $this->calculator()->breakdown($entries);
        self::assertSame(-8.0, $breakdown->noshowPenaltyHours);
        self::assertSame(1, $breakdown->noshowCount);
        self::assertSame(0, $breakdown->completedCount);
    }
}
