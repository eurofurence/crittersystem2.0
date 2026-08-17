<?php

namespace App\Tests\Unit\Backup;

use App\Backup\BackupRetention;
use PHPUnit\Framework\TestCase;

final class BackupRetentionTest extends TestCase
{
    private function ts(string $modify): int
    {
        return (new \DateTimeImmutable('now'))->modify($modify)->getTimestamp();
    }

    public function testSelectsOnlyDumpsOlderThanTheCutoff(): void
    {
        $cutoff = (new \DateTimeImmutable('now'))->modify('-14 days');

        $entries = [
            ['key' => 'critter-20250101-000000.dump', 'lastModified' => $this->ts('-30 days')],
            ['key' => 'critter-20260101-000000.dump', 'lastModified' => $this->ts('-1 day')],
        ];

        $this->assertSame(['critter-20250101-000000.dump'], BackupRetention::expired($entries, $cutoff));
    }

    /** An object in the bucket that is not a backup dump is never pruned, however old it is. */
    public function testIgnoresObjectsThatAreNotBackupDumps(): void
    {
        $entries = [
            ['key' => 'unrelated.txt', 'lastModified' => $this->ts('-100 days')],
            ['key' => 'critter-20250101-000000.sql.gz', 'lastModified' => $this->ts('-100 days')],
            ['key' => 'db/critter-20250101-000000.dump.partial', 'lastModified' => $this->ts('-100 days')],
        ];

        $this->assertSame([], BackupRetention::expired($entries, new \DateTimeImmutable('now')));
    }

    /** Without a modification time there is no proof the object aged out, so it is kept. */
    public function testTreatsMissingModificationTimeAsNotExpired(): void
    {
        $entries = [['key' => 'critter-20250101-000000.dump', 'lastModified' => null]];

        $this->assertSame([], BackupRetention::expired($entries, new \DateTimeImmutable('now')));
    }

    public function testLatestReturnsNullWhenNoDumpsPresent(): void
    {
        $entries = [
            ['key' => 'unrelated.txt', 'lastModified' => $this->ts('-1 day')],
            ['key' => 'critter-connectivity-test-abcd.txt', 'lastModified' => $this->ts('-1 hour')],
        ];

        $this->assertNull(BackupRetention::latest($entries));
    }

    public function testLatestPicksNewestDumpAndCountsOnlyDumps(): void
    {
        $entries = [
            ['key' => 'critter-20260101-000000.dump', 'lastModified' => $this->ts('-30 days')],
            ['key' => 'critter-20260725-022300.dump', 'lastModified' => $this->ts('-1 day')],
            ['key' => 'critter-connectivity-test-abcd.txt', 'lastModified' => $this->ts('-1 hour')],
        ];

        $latest = BackupRetention::latest($entries);

        $this->assertNotNull($latest);
        $this->assertSame('critter-20260725-022300.dump', $latest['key']);
        $this->assertSame(2, $latest['count']);
    }

    /** A store that reports no modification time still yields an instant, parsed from the key. */
    public function testLatestFallsBackToTheTimestampInTheKey(): void
    {
        $entries = [['key' => 'critter-20260725-022300.dump', 'lastModified' => null]];

        $latest = BackupRetention::latest($entries);

        $this->assertNotNull($latest);
        $this->assertInstanceOf(\DateTimeImmutable::class, $latest['at']);
        $this->assertSame('2026-07-25T02:23:00+00:00', $latest['at']->format('c'));
    }
}
