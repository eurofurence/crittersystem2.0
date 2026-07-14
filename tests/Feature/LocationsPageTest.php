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

    public function testNonStaffCannotSeeStaffOnlyLocation(): void
    {
        $staffOnly = (new Location('Backstage'))->setStaffOnly(true);
        $this->em->persist($staffOnly);
        $this->em->flush();

        $this->login(); // non-staff
        $crawler = $this->client->request('GET', '/locations');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Backstage', $crawler->filter('body')->text());

        $this->client->request('GET', '/locations/'.$staffOnly->getUuid());
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaffSeesStaffOnlyLocation(): void
    {
        $staffOnly = (new Location('Backstage'))->setStaffOnly(true);
        $this->em->persist($staffOnly);
        $this->em->flush();

        $this->login('ROLE_STAFF');
        $crawler = $this->client->request('GET', '/locations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Backstage', $crawler->filter('body')->text());
    }

    public function testInternalIntegerIdIsRejectedInUrl(): void
    {
        // Security: resources are addressed by uuid; the internal integer primary key
        // must never resolve as a URL identifier.
        $loc = new Location('Foyer');
        $this->em->persist($loc);
        $this->em->flush();

        $this->login('ROLE_STAFF');

        // The uuid resolves...
        $this->client->request('GET', '/locations/'.$loc->getUuid());
        self::assertResponseIsSuccessful();

        // ...but the integer primary key does not (route requires a uuid → 404).
        $this->client->request('GET', '/locations/'.$loc->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testHiddenParentDoesNotLeakChild(): void
    {
        $root = (new Location('Restricted Wing'))->setStaffOnly(true);
        $child = (new Location('Room 1'))->setParent($root);
        $this->em->persist($root);
        $this->em->persist($child);
        $this->em->flush();

        $this->login(); // non-staff
        // The child inherits staff-only, so it must be 404 for non-staff.
        $this->client->request('GET', '/locations/'.$child->getUuid());
        self::assertResponseStatusCodeSame(404);
    }
}
