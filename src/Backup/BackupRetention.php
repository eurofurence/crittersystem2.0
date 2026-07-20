<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Decides which stored dumps have aged out. Pure logic, kept apart from the S3
 * I/O so it can be exercised without a bucket.
 */
final class BackupRetention
{
    /** critter-YYYYMMDD-HHMMSS.dump - the names {@see \App\Command\BackupDatabaseCommand} writes. */
    public const NAME_PATTERN = '/^critter-\d{8}-\d{6}\.dump$/';

    /**
     * Keys that may be pruned: our own dumps, strictly older than the cutoff.
     *
     * Anything not matching the backup naming is skipped, so a bucket that also
     * holds unrelated objects is never stripped of them - the destination is
     * meant to be dedicated, but a prune must not depend on that being true.
     *
     * @param list<array{key: string, lastModified: ?int}> $entries
     *
     * @return list<string>
     */
    public static function expired(array $entries, \DateTimeImmutable $cutoff): array
    {
        $expired = [];
        foreach ($entries as $entry) {
            if (!preg_match(self::NAME_PATTERN, basename($entry['key']))) {
                continue;
            }
            if ($entry['lastModified'] !== null && $entry['lastModified'] < $cutoff->getTimestamp()) {
                $expired[] = $entry['key'];
            }
        }

        return $expired;
    }
}
