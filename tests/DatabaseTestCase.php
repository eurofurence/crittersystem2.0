<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for tests that need a real database.
 *
 * Skips automatically when no database is reachable (e.g. Postgres not started),
 * and otherwise gives each test an empty database for isolation.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use ResetsDatabase;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
    }
}
