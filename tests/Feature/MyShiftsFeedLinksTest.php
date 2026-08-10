<?php

namespace App\Tests\Feature;

use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The subscription links on /my-shifts.
 *
 * Each feed endpoint is gated by its own privilege, so the page must offer a link only to a viewer
 * whose request that endpoint would actually accept - otherwise the button is a 403 waiting to be
 * clicked.
 */
final class MyShiftsFeedLinksTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    public function testAllThreeFeedsAreOfferedWithTheViewersOwnKey(): void
    {
        $user = $this->scenario->user(['shift:view', 'shift:self', 'export:atom', 'export:ical'], $this->scenario->type);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/my-shifts');

        self::assertResponseIsSuccessful();
        $key = $user->getApiKey();
        foreach (['/api/atom', '/api/ical', '/api/rss'] as $path) {
            self::assertCount(
                1,
                $crawler->filter('a[href="http://localhost'.$path.'?key='.$key.'"]'),
                $path.' must be linked with the viewer own API key',
            );
        }
    }

    public function testTheIcalLinkIsWithheldWithoutTheIcalPrivilege(): void
    {
        $user = $this->scenario->user(['shift:view', 'shift:self', 'export:atom'], $this->scenario->type);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/my-shifts');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href*="/api/atom"]'));
        self::assertCount(1, $crawler->filter('a[href*="/api/rss"]'));
        self::assertCount(0, $crawler->filter('a[href*="/api/ical"]'), 'iCal needs export:ical, so the link must not be offered without it');
    }

    public function testNoFeedLinksWithoutTheExportPrivileges(): void
    {
        $user = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/my-shifts');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href*="key='.$user->getApiKey().'"]'), 'the API key must never appear on the page for a viewer offered no feed');
    }

    /**
     * The key in the href is the viewer's own; a shared page must never carry somebody else's.
     */
    public function testTheLinkCarriesNoOtherUsersKey(): void
    {
        $other = $this->scenario->user(['shift:view', 'shift:self', 'export:ical'], $this->scenario->type);
        $user = $this->scenario->user(['shift:view', 'shift:self', 'export:ical'], $this->scenario->type);
        $this->client->loginUser($user);

        $this->client->request('GET', '/my-shifts');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $other->getApiKey(), (string) $this->client->getResponse()->getContent());
    }
}
