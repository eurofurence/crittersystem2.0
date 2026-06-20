<?php

namespace App\Tests\Unit\Entity;

use App\Entity\DutyRecord;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class DutyRecordTest extends TestCase
{
    public function testDurationOfClosedSession(): void
    {
        $record = new DutyRecord(new User(), null);
        $record->setEndedAt($record->getStartedAt()->modify('+2 hours'));

        self::assertSame(2.0, $record->getDurationHours());
        self::assertFalse($record->isActive());
    }

    public function testOpenSessionIsActiveWithNonNegativeDuration(): void
    {
        $record = new DutyRecord(new User(), null);

        self::assertTrue($record->isActive());
        self::assertGreaterThanOrEqual(0.0, $record->getDurationHours());
    }
}
