<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Location;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Wherever a location is named to a user it carries its ancestor path.
 *
 * Nearly every location in the event is a child - "Hall 5", "Check-in 1", "R 030" - and on its own
 * such a name does not say which building it belongs to, which is what sent volunteers to the wrong
 * place. This covers the surfaces that name one: the browse filter, the shift card and detail, the
 * calendar feed, the bot payload, and the admin list.
 */
final class LocationPathDisplayTest extends DatabaseWebTestCase
{
    private const BOT_TOKEN = 'test-bot-token';

    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    /** Re-parents the scenario's location so every shift it builds sits under a named parent. */
    private function nestScenarioLocation(): Location
    {
        $parent = (new Location('CCH First Floor'))->setAlias('cch-first-floor');
        $this->em->persist($parent);
        $this->scenario->location->setName('Hall 5')->setParent($parent);
        $this->em->flush();

        return $parent;
    }

    public function testTheBrowseFilterNamesTheParent(): void
    {
        $this->nestScenarioLocation();
        $this->scenario->shift('Morning Gate');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $crawler = $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('#f-loc option[value!=""]')->each(static fn ($o): string => trim($o->text()));

        self::assertSame(['CCH First Floor', 'CCH First Floor - Hall 5'], $options);
    }

    public function testTheShiftDetailNamesTheParent(): void
    {
        $this->nestScenarioLocation();
        $shift = $this->scenario->shift('Morning Gate');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts/'.$shift->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CCH First Floor - Hall 5', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCalendarFeedCarriesThePath(): void
    {
        $this->nestScenarioLocation();
        $user = $this->scenario->user(['shift:view', 'shift:self', 'export:ical'], $this->scenario->type);
        $this->scenario->signUp($user, $this->scenario->shift('Morning Gate'));
        $this->em->flush();

        $this->client->request('GET', '/api/ical', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$user->getApiKey()]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('LOCATION:CCH First Floor - Hall 5', (string) $this->client->getResponse()->getContent());
    }

    public function testTheBotPayloadCarriesThePath(): void
    {
        $this->nestScenarioLocation();
        $this->scenario->shift('Morning Gate');
        $actor = $this->scenario->user(memberOf: $this->scenario->type);

        $this->client->request('GET', '/api/bot/shifts', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::BOT_TOKEN,
            'HTTP_X_ACTING_USER' => (string) $actor->getUuid(),
            'HTTP_X_ACTING_TOKEN' => (string) $actor->getTelegramActingToken(),
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertResponseIsSuccessful();
        $shifts = json_decode((string) $this->client->getResponse()->getContent(), true)['shifts'];
        self::assertSame('CCH First Floor - Hall 5', $shifts[0]['location_name']);
    }

    public function testTheAdminListShowsAChildUnderItsParent(): void
    {
        $parent = $this->nestScenarioLocation();

        $group = new Group('Rooms', 'rooms-'.bin2hex(random_bytes(2)));
        $privilege = new Privilege('location:manage');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User();
        $admin->setName('rooms')->setEmail('rooms@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $admin->setPassword($hasher->hashPassword($admin, 'secret123'));
        $admin->addGroup($group);
        $admin->completeOnboarding();
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/manage/locations');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr')->each(static fn ($row): string => preg_replace('/\s+/', ' ', trim($row->text())));

        $parentRow = null;
        $childRow = null;
        foreach ($rows as $index => $text) {
            if (str_contains($text, $parent->getAlias())) {
                $parentRow = $index;
            }
            if (str_contains($text, 'Hall 5')) {
                $childRow = $index;
            }
        }

        self::assertNotNull($parentRow);
        self::assertNotNull($childRow);
        self::assertSame($parentRow + 1, $childRow, 'a child location must be listed directly under its own parent');
        self::assertStringContainsString('CCH First Floor - Hall 5', $rows[$childRow], 'the child row must name the parent it belongs to');
    }
}
