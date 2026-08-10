<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Owns the test database schema for the lifetime of one PHPUnit process.
 *
 * The schema is built exactly once; every test after that starts from a TRUNCATE
 * of the tables the previous one actually wrote to. The isolation is the same -
 * each test still begins with an empty database and identity sequences at 1 -
 * but re-running the DDL for ~70 entities costs several seconds per test.
 *
 * Truncating all 82 tables unconditionally is the most expensive thing the suite
 * does. TRUNCATE rewrites a relation file and takes an ACCESS EXCLUSIVE lock
 * whether or not the table holds anything, so an empty table is not free: the full
 * sweep costs ~1.5s against a durable Postgres and ~156ms against the throwaway one
 * the suite uses. Probing for the few tables a test actually dirtied is two catalog
 * round trips and brings it to ~9ms - the difference between a run measured in
 * minutes and one measured in tens of minutes.
 */
final class TestSchema
{
    private static bool $created = false;

    /** @var list<string>|null Table names, read once; the schema does not change within a process. */
    private static ?array $tables = null;

    /** Whether the database is reachable at all (Postgres may simply not be running). */
    public static function isAvailable(EntityManagerInterface $em): bool
    {
        try {
            $em->getConnection()->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Leave the caller with an empty database.
     *
     * The early return is not a shortcut: a schema that was just created is already empty, and
     * there is no table list to probe yet.
     */
    public static function reset(EntityManagerInterface $em): void
    {
        if (!self::$created) {
            $schemaTool = new SchemaTool($em);
            $schemaTool->dropDatabase();
            $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
            self::$created = true;
            self::$tables = null;

            return;
        }

        self::truncateDirty($em->getConnection());
    }

    /**
     * CASCADE because of the FK graph; RESTART IDENTITY so ids start from 1 again and tests cannot
     * come to depend on ids left behind by earlier ones.
     */
    private static function truncateDirty(Connection $connection): void
    {
        $tables = self::tables($connection);
        if ($tables === []) {
            return;
        }

        $dirty = array_unique([...self::tablesWithRows($connection, $tables), ...self::tablesWithUsedSequence($connection)]);
        if ($dirty === []) {
            return;
        }

        $platform = $connection->getDatabasePlatform();
        $quoted = array_map(static fn (string $t): string => $platform->quoteIdentifier($t), $dirty);

        $connection->executeStatement('TRUNCATE TABLE '.implode(', ', $quoted).' RESTART IDENTITY CASCADE');
    }

    /**
     * Tables holding at least one row, as one query rather than 82.
     *
     * EXISTS stops at the first row, so a table that is already empty costs a scan
     * of zero pages - which is why probing is orders of magnitude cheaper than
     * truncating on the chance that something is there.
     *
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private static function tablesWithRows(Connection $connection, array $tables): array
    {
        $probes = array_map(
            static fn (string $t): string => sprintf(
                'SELECT %s AS t WHERE EXISTS (SELECT 1 FROM %s)',
                $connection->quote($t),
                $connection->getDatabasePlatform()->quoteIdentifier($t),
            ),
            $tables,
        );

        return $connection->fetchFirstColumn(implode(' UNION ALL ', $probes));
    }

    /**
     * Tables whose identity sequence has handed out a value.
     *
     * A test that inserts rows and deletes them again leaves no rows behind but does
     * leave the sequence advanced, and the next test would then see ids that do not
     * start at 1. Postgres reports a sequence that was never used as a NULL
     * last_value, which distinguishes the two exactly.
     *
     * @return list<string>
     */
    private static function tablesWithUsedSequence(Connection $connection): array
    {
        return $connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT owner.relname
                FROM pg_class sequence
                JOIN pg_depend dependency ON dependency.objid = sequence.oid AND dependency.deptype = 'a'
                JOIN pg_class owner ON owner.oid = dependency.refobjid
                JOIN pg_sequences state ON state.sequencename = sequence.relname AND state.schemaname = current_schema()
                WHERE sequence.relkind = 'S' AND state.last_value IS NOT NULL
                SQL,
        );
    }

    /**
     * Read the tables back from the database rather than from the metadata: that
     * also catches join tables (group_privileges, …), which are not entities of
     * their own.
     *
     * @return list<string>
     */
    private static function tables(Connection $connection): array
    {
        return self::$tables ??= $connection->fetchFirstColumn(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()',
        );
    }
}
