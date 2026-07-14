<?php

namespace App\Tests\Integration;

use App\Entity\Department;
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

    private function shift(): Shift
    {
        $dept = new Department('D '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 12:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
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
