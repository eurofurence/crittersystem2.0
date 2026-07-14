<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Owns the test database schema for the lifetime of one PHPUnit process.
 *
 * The schema is built exactly once; every test after that starts from a single
 * TRUNCATE of every table instead of a full drop/create. The isolation is the
 * same — each test still begins with an empty database — but re-running the DDL
 * for ~70 entities costs several seconds per test, and truncating costs
 * milliseconds.
 */
final class TestSchema
{
    private static bool $created = false;

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

    /** Leave the caller with an empty database. */
    public static function reset(EntityManagerInterface $em): void
    {
        if (!self::$created) {
            $schemaTool = new SchemaTool($em);
            $schemaTool->dropDatabase();
            $schemaTool->createSchema($em->getMetadataFactory()->getAllMetadata());
            self::$created = true;

            // A freshly created schema is already empty.
            return;
        }

        self::truncateAll($em);
    }

    private static function truncateAll(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $platform = $connection->getDatabasePlatform();

        // Read the tables back from the database rather than from the metadata:
        // that also catches join tables (group_privileges, …), which are not
        // entities of their own.
        $tables = $connection->fetchFirstColumn(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()',
        );
        if ($tables === []) {
            return;
        }

        $quoted = array_map(static fn (string $t): string => $platform->quoteIdentifier($t), $tables);

        // CASCADE because of the FK graph; RESTART IDENTITY so ids start from 1
        // again and tests cannot come to depend on ids left behind by earlier ones.
        $connection->executeStatement('TRUNCATE TABLE '.implode(', ', $quoted).' RESTART IDENTITY CASCADE');
    }
}
