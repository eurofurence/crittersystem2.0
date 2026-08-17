<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Service\Shift\ShiftWizardService;
use App\Tests\DatabaseTestCase;

/**
 * Shift Wizard generation: a day window is sliced into fixed slots,
 * repeated across the selected days, all as drafts.
 */
final class ShiftWizardServiceTest extends DatabaseTestCase
{
    private \DateTimeZone $utc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->utc = new \DateTimeZone('UTC');
    }

    private function wizard(): ShiftWizardService
    {
        return static::getContainer()->get(ShiftWizardService::class);
    }

    private function dept(): Department
    {
        $d = new Department('Dept '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    /** A 10:00 to 14:00 window with 120-minute slots yields 10-12 and 12-14. */
    public function testSlotsForDaySplitEvenly(): void
    {
        $slots = $this->wizard()->slotsForDay('2026-06-01', '10:00', '14:00', 120, $this->utc);
        self::assertCount(2, $slots);
        self::assertSame('10:00', $slots[0][0]->format('H:i'));
        self::assertSame('12:00', $slots[0][1]->format('H:i'));
        self::assertSame('14:00', $slots[1][1]->format('H:i'));
    }

    /** 10:00 to 13:00 in 2h slots yields only the full 10-12 slot; the partial 12-13 is dropped. */
    public function testTrailingPartialSlotIsDropped(): void
    {
        $slots = $this->wizard()->slotsForDay('2026-06-01', '10:00', '13:00', 120, $this->utc);
        self::assertCount(1, $slots);
    }

    /** 22:00 to 02:00 is a 4h window, so 2h slots run 22-00 and 00-02 on the following day. */
    public function testOvernightWindowWrapsPastMidnight(): void
    {
        $slots = $this->wizard()->slotsForDay('2026-06-01', '22:00', '02:00', 120, $this->utc);
        self::assertCount(2, $slots);
        self::assertSame('2026-06-02 02:00', $slots[1][1]->format('Y-m-d H:i'));
    }

    /** Two slots a day over two days produce four shifts, all created as drafts. */
    public function testGenerateRepeatsAcrossDaysAsDrafts(): void
    {
        $dept = $this->dept();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->em->flush();

        $created = $this->wizard()->generate(
            $dept,
            ['2026-06-01', '2026-06-02'],
            '10:00',
            '14:00',
            120,
            $this->utc,
            ShiftAudience::DEPARTMENT_STAFF,
            null,
            null,
            [[$type, 2]],
        );

        self::assertCount(4, $created);
        foreach ($created as $shift) {
            self::assertSame(ShiftState::DRAFT, $shift->getState());
            self::assertSame(ShiftAudience::DEPARTMENT_STAFF, $shift->getAudience());
            self::assertSame(2.0, $shift->getDurationHours());
            self::assertCount(1, $shift->getNeededVolunteerTypes());
            self::assertSame(2, $shift->getNeededVolunteerTypes()->first()->getCount());
        }
    }

    public function testTooShortSlotIsRejected(): void
    {
        $dept = $this->dept();
        $this->expectException(\InvalidArgumentException::class);
        $this->wizard()->generate($dept, ['2026-06-01'], '10:00', '14:00', 15, $this->utc);
    }
}
