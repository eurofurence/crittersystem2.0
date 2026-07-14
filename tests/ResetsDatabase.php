<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a test an empty database, skipping the test when none is reachable
 * (e.g. Postgres not started). See {@see TestSchema} for why this truncates
 * rather than recreating the schema.
 */
trait ResetsDatabase
{
    protected function resetSchema(EntityManagerInterface $em): void
    {
        if (!TestSchema::isAvailable($em)) {
            self::markTestSkipped('Database not available');
        }

        TestSchema::reset($em);
    }
}
