<?php

namespace App\Tests\Feature;

use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;

final class LandingTest extends DatabaseWebTestCase
{
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

    public function testSsoButtonHiddenWhenSsoDisabled(): void
    {
        // SSO_ENABLED defaults to 0 in the test environment, so no SSO link is shown.
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('a:contains("Sign in with")')->count());
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
