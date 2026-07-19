<?php

namespace App\Tests\Feature;

use App\Tests\DatabaseWebTestCase;

/**
 * The install wizard unlock step. A fresh database (no users) makes the wizard reachable, and the
 * password is compared against INSTALL_PASSWORD - which deployment tooling routinely delivers with a
 * trailing newline that must not cause a correct password to be rejected.
 */
final class InstallPasswordTest extends DatabaseWebTestCase
{
    private const PASSWORD = 'regression-secret';

    protected function setUp(): void
    {
        // Simulate the env value arriving with a trailing newline (secret file, $(cat ...), echo).
        $_ENV['INSTALL_PASSWORD'] = self::PASSWORD."\n";
        $_SERVER['INSTALL_PASSWORD'] = self::PASSWORD."\n";
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_ENV['INSTALL_PASSWORD'], $_SERVER['INSTALL_PASSWORD']);
        parent::tearDown();
    }

    public function testCorrectPasswordIsAcceptedDespiteATrailingNewlineInTheEnv(): void
    {
        $crawler = $this->client->request('GET', '/admin/install');
        self::assertResponseIsSuccessful('a fresh install must show the unlock page');

        // The operator types the password exactly, without the stray newline the env carries.
        $this->client->submit($crawler->selectButton('Unlock')->form(['password' => self::PASSWORD]));

        self::assertResponseRedirects('/admin/install/overview');
    }

    public function testAWrongPasswordIsStillRejected(): void
    {
        $crawler = $this->client->request('GET', '/admin/install');
        $this->client->submit($crawler->selectButton('Unlock')->form(['password' => 'not-it']));

        self::assertResponseRedirects('/admin/install');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Incorrect installation password');
    }
}
