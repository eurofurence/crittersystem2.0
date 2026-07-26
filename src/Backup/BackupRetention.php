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

    /**
     * The most recent dump, or null if the bucket holds none of ours. Uses the
     * same naming rule as {@see expired()} so a bucket that also holds unrelated
     * objects is not mistaken for a backup. Keys embed a sortable UTC timestamp,
     * so the lexically greatest key is the newest; the reported instant is the
     * stored modification time, or that encoded in the key when the store has none.
     *
     * @param list<array{key: string, lastModified: ?int}> $entries
     *
     * @return array{key: string, at: ?\DateTimeImmutable, count: int}|null
     */
    public static function latest(array $entries): ?array
    {
        $dumps = array_values(array_filter(
            $entries,
            static fn (array $e): bool => preg_match(self::NAME_PATTERN, basename($e['key'])) === 1,
        ));
        if ($dumps === []) {
            return null;
        }

        usort($dumps, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        $newest = $dumps[array_key_last($dumps)];

        return ['key' => $newest['key'], 'at' => self::timeOf($newest), 'count' => count($dumps)];
    }

    /** @param array{key: string, lastModified: ?int} $entry */
    private static function timeOf(array $entry): ?\DateTimeImmutable
    {
        if ($entry['lastModified'] !== null) {
            return (new \DateTimeImmutable())->setTimestamp($entry['lastModified']);
        }
        if (preg_match('/(\d{8})-(\d{6})\.dump$/', $entry['key'], $m) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new \DateTimeZone('UTC'));

            return $parsed !== false ? $parsed : null;
        }

        return null;
    }
}
