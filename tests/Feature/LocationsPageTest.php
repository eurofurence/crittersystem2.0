<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Location;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LocationsPageTest extends DatabaseWebTestCase
{
    private function login(?string $role = null): void
    {
        $group = new Group('G', 'g-grp', $role);
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('viewer')->setEmail('viewer@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    /** A staff-only location is absent from the list for a non-staff viewer, and 404 on its own page. */
    public function testNonStaffCannotSeeStaffOnlyLocation(): void
    {
        $staffOnly = (new Location('Backstage'))->setAlias('backstage')->setStaffOnly(true);
        $this->em->persist($staffOnly);
        $this->em->flush();

        $this->login();
        $crawler = $this->client->request('GET', '/locations');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Backstage', $crawler->filter('body')->text());

        $this->client->request('GET', '/locations/'.$staffOnly->getUuid());
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaffSeesStaffOnlyLocation(): void
    {
        $staffOnly = (new Location('Backstage'))->setAlias('backstage')->setStaffOnly(true);
        $this->em->persist($staffOnly);
        $this->em->flush();

        $this->login('ROLE_STAFF');
        $crawler = $this->client->request('GET', '/locations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Backstage', $crawler->filter('body')->text());
    }

    /**
     * Resources are addressed by uuid: the uuid resolves, and the internal integer primary key
     * must never resolve as a URL identifier, because it reveals record counts and creation order.
     */
    public function testInternalIntegerIdIsRejectedInUrl(): void
    {
        $loc = (new Location('Foyer'))->setAlias('foyer');
        $this->em->persist($loc);
        $this->em->flush();

        $this->login('ROLE_STAFF');

        $this->client->request('GET', '/locations/'.$loc->getUuid());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/locations/'.$loc->getId());
        self::assertResponseStatusCodeSame(404);
    }

    /** A child of a staff-only location inherits it, so a non-staff viewer gets a 404 for the child. */
    public function testHiddenParentDoesNotLeakChild(): void
    {
        $root = (new Location('Restricted Wing'))->setAlias('restricted-wing')->setStaffOnly(true);
        $child = (new Location('Room 1'))->setAlias('room-1')->setParent($root);
        $this->em->persist($root);
        $this->em->persist($child);
        $this->em->flush();

        $this->login();
        $this->client->request('GET', '/locations/'.$child->getUuid());
        self::assertResponseStatusCodeSame(404);
    }
}
