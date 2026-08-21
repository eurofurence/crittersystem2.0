<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\User;
use App\Service\Shift\ShiftFilterMemory;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Filters chosen on the volunteer shift list survive leaving the page and coming back.
 *
 * They used to live only in the query string, so anything that navigated away lost them and the
 * list came back unfiltered.
 */
final class ShiftFilterPersistenceTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function volunteer(): User
    {
        $group = new Group('Browsers', 'browse-'.bin2hex(random_bytes(3)), 'ROLE_USER');
        foreach (['shift:view', 'shift:self'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('vol-'.bin2hex(random_bytes(3)))->setEmail('vol-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $settings = new Settings($user);
        $user->setSettings($settings);
        $this->em->persist($settings);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function storedBrowseFilters(User $user): array
    {
        $this->em->clear();

        return static::getContainer()->get(ShiftFilterMemory::class)->recall(
            $this->em->getRepository(User::class)->find($user->getId()),
            ShiftFilterMemory::SURFACE_BROWSE,
        );
    }

    public function testChoosingAFilterIsRememberedForTheNextVisit(): void
    {
        $user = $this->volunteer();

        $this->client->request('GET', '/shifts?f=1&available=1');
        self::assertResponseIsSuccessful();

        self::assertSame(['available' => '1'], $this->storedBrowseFilters($user));
    }

    /** Arriving with nothing in the URL puts the checkbox back the way they left it. */
    public function testAPlainVisitComesBackFiltered(): void
    {
        $this->volunteer();
        $this->client->request('GET', '/shifts?f=1&available=1');

        $crawler = $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty(
            $crawler->filter('#f-avail[checked]'),
            'the remembered filter is applied and shown as applied',
        );
    }

    /** Clearing every box has to stick, which is what the form marker exists for. */
    public function testClearingTheFiltersSticks(): void
    {
        $user = $this->volunteer();
        $this->client->request('GET', '/shifts?f=1&available=1');

        $this->client->request('GET', '/shifts?f=1');

        self::assertSame([], $this->storedBrowseFilters($user));
    }

    /** The day is not a remembered filter, so it never comes back on a later visit. */
    public function testTheDayIsNotRemembered(): void
    {
        $user = $this->volunteer();

        $this->client->request('GET', '/shifts?f=1&available=1&date=2026-08-01');

        self::assertArrayNotHasKey('date', $this->storedBrowseFilters($user));
    }
}
