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
 * Availability consumption: a confirmed assignment consumes
 * overlapping availability across departments; applications and (draft) proposals
 * do not; removing an assignment recomputes effective availability.
 */
final class AvailabilityConsumptionTest extends DatabaseTestCase
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

    private function shift(string $start, string $end): Shift
    {
        $dept = new Department('D '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($dept);
        $this->em->persist($shift);

        return $shift;
    }

    private function entry(Shift $shift, User $user, ShiftEntryState $state): ShiftEntry
    {
        $type = new VolunteerType('T'.bin2hex(random_bytes(3)));
        $this->em->persist($type);
        $entry = new ShiftEntry($shift, $type, $user);
        $entry->setState($state);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function declare(User $user, string $start, string $end, AvailabilityValue $value): void
    {
        $this->service()->submit($user, [
            ['start' => new \DateTimeImmutable($start), 'end' => new \DateTimeImmutable($end), 'value' => $value],
        ], null);
    }

    public function testConfirmedAssignmentConsumesOverlappingAvailability(): void
    {
        $user = $this->user();
        $this->declare($user, '2026-06-01 10:00', '2026-06-01 14:00', AvailabilityValue::PREFERRED);
        $this->entry($this->shift('2026-06-01 11:00', '2026-06-01 12:00'), $user, ShiftEntryState::ASSIGNMENT);

        $state = $this->service()->planningState($user, new \DateTimeImmutable('2026-06-01 11:15'), new \DateTimeImmutable('2026-06-01 11:45'));
        self::assertTrue($state['occupied']);
    }

    public function testApplicationDoesNotConsume(): void
    {
        $user = $this->user();
        $this->declare($user, '2026-06-01 10:00', '2026-06-01 14:00', AvailabilityValue::PREFERRED);
        $this->entry($this->shift('2026-06-01 11:00', '2026-06-01 12:00'), $user, ShiftEntryState::APPLICATION);

        $state = $this->service()->planningState($user, new \DateTimeImmutable('2026-06-01 11:15'), new \DateTimeImmutable('2026-06-01 11:45'));
        self::assertFalse($state['occupied'], 'only confirmed assignments consume');
        self::assertSame(AvailabilityValue::PREFERRED, $state['value']);
    }

    public function testRemovingAssignmentRecomputes(): void
    {
        $user = $this->user();
        $this->declare($user, '2026-06-01 10:00', '2026-06-01 14:00', AvailabilityValue::AVAILABLE);
        $entry = $this->entry($this->shift('2026-06-01 11:00', '2026-06-01 12:00'), $user, ShiftEntryState::ASSIGNMENT);

        self::assertTrue($this->service()->planningState($user, new \DateTimeImmutable('2026-06-01 11:15'), new \DateTimeImmutable('2026-06-01 11:45'))['occupied']);

        $this->em->remove($entry);
        $this->em->flush();

        $state = $this->service()->planningState($user, new \DateTimeImmutable('2026-06-01 11:15'), new \DateTimeImmutable('2026-06-01 11:45'));
        self::assertFalse($state['occupied'], 'removal frees the availability');
        self::assertSame(AvailabilityValue::AVAILABLE, $state['value']);
    }

    public function testPlanningStateReturnsWorstDeclaredValue(): void
    {
        $user = $this->user();
        $this->service()->submit($user, [
            ['start' => new \DateTimeImmutable('2026-06-01 10:00'), 'end' => new \DateTimeImmutable('2026-06-01 12:00'), 'value' => AvailabilityValue::AVOID],
            ['start' => new \DateTimeImmutable('2026-06-01 12:00'), 'end' => new \DateTimeImmutable('2026-06-01 14:00'), 'value' => AvailabilityValue::UNAVAILABLE],
        ], null);

        $state = $this->service()->planningState($user, new \DateTimeImmutable('2026-06-01 10:00'), new \DateTimeImmutable('2026-06-01 14:00'));
        self::assertFalse($state['occupied']);
        self::assertSame(AvailabilityValue::UNAVAILABLE, $state['value'], 'worst (least willing) value wins');
    }

    /** Re-planning the very shift a user is already on must not count them as occupied. */
    public function testExcludedShiftIsNotConsumed(): void
    {
        $user = $this->user();
        $this->declare($user, '2026-06-01 10:00', '2026-06-01 14:00', AvailabilityValue::PREFERRED);
        $shift = $this->shift('2026-06-01 11:00', '2026-06-01 12:00');
        $this->entry($shift, $user, ShiftEntryState::ASSIGNMENT);

        $state = $this->service()->planningState($user, new \DateTimeImmutable('2026-06-01 11:15'), new \DateTimeImmutable('2026-06-01 11:45'), $shift);
        self::assertFalse($state['occupied']);
    }
}
