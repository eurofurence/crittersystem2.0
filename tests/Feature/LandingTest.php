<?php

namespace App\Tests\Feature;

use App\Service\EventConfigStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LandingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: '.$e->getMessage());
        }

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testRootRedirectsGuestToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLoginPageIsPublicAndShowsLoginForm(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
    }

    public function testSsoButtonIsPresentButDisabled(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $sso = $crawler->filter('button[disabled]');
        self::assertGreaterThan(0, $sso->count());
        self::assertStringContainsString('SSO', $sso->text());
    }

    public function testLandingShowsEventNameAndTimeline(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_NAME, 'Eurofurence 28');
        $store->set(EventConfigStore::KEY_EVENT_START, '2030-09-01T10:00:00+00:00');
        $store->flush();

        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Eurofurence 28');
        self::assertSelectorTextContains('body', 'Event starts');
        self::assertSelectorTextContains('body', '2030');
    }
}
