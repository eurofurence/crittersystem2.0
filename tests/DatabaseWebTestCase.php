<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for feature tests that drive the app through a browser client and
 * need a real database. Same guarantees as {@see DatabaseTestCase}: the test is
 * skipped when no database is reachable, and starts from an empty one.
 *
 * Reboot is disabled on the client so the kernel booted here — and with it the
 * EntityManager the test writes its fixtures through — stays the one serving the
 * requests.
 */
abstract class DatabaseWebTestCase extends WebTestCase
{
    use ResetsDatabase;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);
    }
}
