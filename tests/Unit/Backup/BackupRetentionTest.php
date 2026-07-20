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
            ['key' => 'critter-20250101-000000.dump', 'lastModified' => $this->ts('-30 days')], // old -> prune
            ['key' => 'critter-20260101-000000.dump', 'lastModified' => $this->ts('-1 day')],   // fresh -> keep
        ];

        $this->assertSame(['critter-20250101-000000.dump'], BackupRetention::expired($entries, $cutoff));
    }

    public function testIgnoresObjectsThatAreNotBackupDumps(): void
    {
        // A stray object in the bucket must never be pruned, however old it is.
        $entries = [
            ['key' => 'unrelated.txt', 'lastModified' => $this->ts('-100 days')],
            ['key' => 'critter-20250101-000000.sql.gz', 'lastModified' => $this->ts('-100 days')],
            ['key' => 'db/critter-20250101-000000.dump.partial', 'lastModified' => $this->ts('-100 days')],
        ];

        $this->assertSame([], BackupRetention::expired($entries, new \DateTimeImmutable('now')));
    }

    public function testTreatsMissingModificationTimeAsNotExpired(): void
    {
        // Without a timestamp we cannot prove the object aged out, so it is kept.
        $entries = [['key' => 'critter-20250101-000000.dump', 'lastModified' => null]];

        $this->assertSame([], BackupRetention::expired($entries, new \DateTimeImmutable('now')));
    }
}
