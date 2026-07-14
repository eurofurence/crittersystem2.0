<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\AvailabilityValue;
use App\Enum\ShiftEntryState;
use App\Service\Availability\AvailabilityService;
use App\Tests\DatabaseTestCase;

/**
 * Global Planning Availability: a user has one schedule of valued ranges that is global, not per
 * department, and confirmed assignments surface as occupied overlays.
 */
final class AvailabilityServiceTest extends DatabaseTestCase
{
    private function service(): AvailabilityService
    {
        return static::getContainer()->get(AvailabilityService::class);
    }

    private function user(): User
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function range(string $start, string $end, AvailabilityValue $value): array
    {
        return ['start' => new \DateTimeImmutable($start), 'end' => new \DateTimeImmutable($end), 'value' => $value];
    }

    public function testSubmitStoresValuedRangesAndComment(): void
    {
        $user = $this->user();
        $this->service()->submit($user, [
            $this->range('2026-06-01 10:00', '2026-06-01 14:00', AvailabilityValue::PREFERRED),
            $this->range('2026-06-01 18:00', '2026-06-01 22:00', AvailabilityValue::UNAVAILABLE),
        ], 'Reachable by phone');
        $this->em->clear();

        $ranges = $this->service()->rangesForUser($user);
        self::assertCount(2, $ranges);
        $values = array_map(static fn ($r) => $r->getValue(), $ranges);
        self::assertContains(AvailabilityValue::PREFERRED, $values);
        self::assertContains(AvailabilityValue::UNAVAILABLE, $values);
    }

    public function testSubmitConsolidatesTouchingSameValueRanges(): void
    {
        $user = $this->user();
        $this->service()->submit($user, [
            $this->range('2026-06-01 10:00', '2026-06-01 12:00', AvailabilityValue::AVAILABLE),
            $this->range('2026-06-01 12:00', '2026-06-01 14:00', AvailabilityValue::AVAILABLE),
        ], null);
        $this->em->clear();

        $ranges = $this->service()->rangesForUser($user);
        self::assertCount(1, $ranges, 'touching same-value ranges merge');
        self::assertSame('10:00', $ranges[0]->getStartsAt()->format('H:i'));
        self::assertSame('14:00', $ranges[0]->getEndsAt()->format('H:i'));
    }

    public function testResubmitReplacesTheGlobalSchedule(): void
    {
        $user = $this->user();
        $this->service()->submit($user, [$this->range('2026-06-01 10:00', '2026-06-01 12:00', AvailabilityValue::AVAILABLE)], null);
        $this->service()->submit($user, [$this->range('2026-06-02 10:00', '2026-06-02 12:00', AvailabilityValue::PREFERRED)], null);
        $this->em->clear();

        $ranges = $this->service()->rangesForUser($user);
        self::assertCount(1, $ranges, 'the schedule is replaced, not appended');
        self::assertSame(AvailabilityValue::PREFERRED, $ranges[0]->getValue());
    }

    public function testScheduleIsGlobalWithASingleHeaderPerUser(): void
    {
        $user = $this->user();
        $first = $this->service()->getOrCreate($user);
        $this->em->flush();
        $second = $this->service()->getOrCreate($user);

        self::assertSame($first->getId(), $second->getId(), 'one global schedule per user');
    }

    public function testConfirmedAssignmentsBecomeOccupiedOverlays(): void
    {
        $user = $this->user();
        $dept = new Department('D', 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 14:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $entry = new ShiftEntry($shift, $type, $user);
        $entry->setState(ShiftEntryState::ASSIGNMENT);
        $this->em->persist($entry);
        $this->em->flush();

        $overlays = $this->service()->occupiedOverlays($user);
        self::assertCount(1, $overlays);
        self::assertSame('Gate', $overlays[0]['title']);
    }
}
