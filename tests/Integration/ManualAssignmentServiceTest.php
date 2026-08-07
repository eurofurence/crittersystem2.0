<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\AvailabilityValue;
use App\Service\Assignment\ManualAssignmentService;
use App\Service\Availability\AvailabilityService;
use App\Tests\DatabaseTestCase;

/**
 * Manual assignment overrides: assigning against Avoid/Unavailable
 * availability needs an explicit override, which is marked on the entry and
 * audited; a plain-available assignment is not marked.
 */
final class ManualAssignmentServiceTest extends DatabaseTestCase
{
    private function service(): ManualAssignmentService
    {
        return static::getContainer()->get(ManualAssignmentService::class);
    }

    private function availability(): AvailabilityService
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

    private function shift(string $start = '2026-06-01 10:00', string $end = '2026-06-01 12:00'): Shift
    {
        $dept = new Department('D '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    private function makeStaff(): User
    {
        $group = new Group('Staff', 'staff-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        $this->em->persist($group);
        $user = $this->user();
        $user->addGroup($group);
        $this->em->flush();

        return $user;
    }

    private function type(): VolunteerType
    {
        $t = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($t);
        $this->em->flush();

        return $t;
    }

    public function testAvailableAssignmentIsNotMarked(): void
    {
        $user = $this->user();
        $shift = $this->shift();
        $this->availability()->submit($user, [
            ['start' => new \DateTimeImmutable('2026-06-01 09:00'), 'end' => new \DateTimeImmutable('2026-06-01 13:00'), 'value' => AvailabilityValue::AVAILABLE],
        ], null);

        $entry = $this->service()->assign($shift, $user, $this->type());
        self::assertFalse($entry->isOverridden());
    }

    public function testUnavailableAssignmentRequiresOverride(): void
    {
        $user = $this->user();
        $shift = $this->shift();
        $this->availability()->submit($user, [
            ['start' => new \DateTimeImmutable('2026-06-01 09:00'), 'end' => new \DateTimeImmutable('2026-06-01 13:00'), 'value' => AvailabilityValue::UNAVAILABLE],
        ], null);

        $this->expectException(\RuntimeException::class);
        $this->service()->assign($shift, $user, $this->type());
    }

    public function testOverrideIsMarkedAndRecorded(): void
    {
        $user = $this->user();
        $shift = $this->shift();
        $this->availability()->submit($user, [
            ['start' => new \DateTimeImmutable('2026-06-01 09:00'), 'end' => new \DateTimeImmutable('2026-06-01 13:00'), 'value' => AvailabilityValue::AVOID],
        ], null);

        $entry = $this->service()->assign($shift, $user, $this->type(), override: true);
        self::assertTrue($entry->isOverridden());
        self::assertStringContainsString('availability', (string) $entry->getOverrideReason());
    }

    public function testInspectSurfacesAvailabilityWarning(): void
    {
        $user = $this->user();
        $shift = $this->shift();
        $this->availability()->submit($user, [
            ['start' => new \DateTimeImmutable('2026-06-01 09:00'), 'end' => new \DateTimeImmutable('2026-06-01 13:00'), 'value' => AvailabilityValue::UNAVAILABLE],
        ], null);

        $inspection = $this->service()->inspect($shift, $user);
        self::assertTrue($inspection['needsOverride']);
        self::assertNotEmpty($inspection['warnings']);
    }

    /**
     * A volunteer is held to one shift at a time, so double-booking one is a decision the manager
     * has to take deliberately.
     */
    public function testAssigningAVolunteerOverAnOverlapNeedsAnOverride(): void
    {
        $user = $this->user();
        $type = $this->type();
        $first = $this->shift('2026-06-01 10:00', '2026-06-01 12:00');
        $second = $this->shift('2026-06-01 11:00', '2026-06-01 13:00');
        $this->service()->assign($first, $user, $type);

        $inspection = $this->service()->inspect($second, $user);

        self::assertTrue($inspection['needsOverride']);
        self::assertSame(['occupied'], array_column($inspection['warnings'], 'key'));
    }

    /**
     * Staff may work parallel shifts, so the manager is told about the clash but is not made to
     * override a rule that does not apply to this person.
     */
    public function testAssigningStaffOverAnOverlapIsAllowedWithoutAnOverride(): void
    {
        $user = $this->makeStaff();
        $type = $this->type();
        $first = $this->shift('2026-06-01 10:00', '2026-06-01 12:00');
        $second = $this->shift('2026-06-01 11:00', '2026-06-01 13:00');
        $this->service()->assign($first, $user, $type);

        $inspection = $this->service()->inspect($second, $user);
        self::assertFalse($inspection['needsOverride']);
        self::assertSame(['occupied'], array_column($inspection['warnings'], 'key'), 'the clash is still reported');

        $entry = $this->service()->assign($second, $user, $type);
        self::assertFalse($entry->isOverridden());
        self::assertCount(2, $this->em->getRepository(ShiftEntry::class)->findBy(['user' => $user]));
    }

    public function testRemoveDeletesTheEntry(): void
    {
        $user = $this->user();
        $shift = $this->shift();
        $entry = $this->service()->assign($shift, $user, $this->type());
        $this->service()->remove($entry);
        $this->em->clear();

        self::assertSame(0, $this->em->getRepository(ShiftEntry::class)->count(['shift' => $shift->getId()]));
    }
}
