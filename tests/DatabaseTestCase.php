<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for tests that need a real database.
 *
 * Skips automatically when no database is reachable (e.g. Postgres not started),
 * and otherwise gives each test a freshly created schema for isolation.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);
    }

    protected function resetSchema(EntityManagerInterface $em): void
    {
        try {
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: '.$e->getMessage());
        }

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }
}
