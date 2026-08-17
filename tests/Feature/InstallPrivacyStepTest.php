<?php

namespace App\Tests\Feature;

use App\Entity\PrivacyNotice;
use App\Tests\DatabaseWebTestCase;

/**
 * The install wizard's privacy-notice step. It captures only the essentials
 * (event name, data controller, contact email, retention period) and stores
 * them on the single privacy notice, leaving the full body at its shipped
 * default for later editing.
 */
final class InstallPrivacyStepTest extends DatabaseWebTestCase
{
    private const PASSWORD = 'regression-secret';

    protected function setUp(): void
    {
        $_ENV['INSTALL_PASSWORD'] = self::PASSWORD;
        $_SERVER['INSTALL_PASSWORD'] = self::PASSWORD;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_ENV['INSTALL_PASSWORD'], $_SERVER['INSTALL_PASSWORD']);
        parent::tearDown();
    }

    private function unlock(): void
    {
        $crawler = $this->client->request('GET', '/admin/install');
        $this->client->submit($crawler->selectButton('Unlock')->form(['password' => self::PASSWORD]));
    }

    /**
     * The install step stores the four essentials it asks for. It does not ask for the notice
     * body, but a default body must be seeded all the same so there is something to edit later.
     */
    public function testSavingTheEssentialsStoresThemOnThePrivacyNotice(): void
    {
        $this->unlock();

        $crawler = $this->client->request('GET', '/admin/install/privacy');
        self::assertResponseIsSuccessful('the privacy step must render once unlocked');

        $this->client->submit($crawler->selectButton('Save & finish')->form([
            'event_name' => 'Eurofurence 28',
            'controller_org' => 'Eurofurence e.V.',
            'contact_email' => 'privacy@example.org',
            'deletion_days' => '30',
        ]));
        self::assertResponseRedirects('/admin/install/finish');

        $notice = self::getContainer()->get('doctrine')->getRepository(PrivacyNotice::class)->findOneBy([]);
        self::assertNotNull($notice);
        self::assertSame('Eurofurence 28', $notice->getEventName());
        self::assertSame('Eurofurence e.V.', $notice->getControllerOrg());
        self::assertSame('privacy@example.org', $notice->getContactEmail());
        self::assertSame(30, $notice->getDeletionDays());
        self::assertStringContainsString('Privacy Notice', $notice->getBodyHtml());
    }

    public function testSkippingLeavesNoPrivacyNotice(): void
    {
        $this->unlock();

        $crawler = $this->client->request('GET', '/admin/install/privacy');
        $this->client->submit($crawler->selectButton('Skip for now')->form());
        self::assertResponseRedirects('/admin/install/finish');

        $notices = self::getContainer()->get('doctrine')->getRepository(PrivacyNotice::class)->findAll();
        self::assertCount(0, $notices, 'skipping must not persist a notice');
    }
}
