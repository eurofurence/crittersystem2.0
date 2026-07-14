<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\RequestLink;
use App\Entity\User;
use App\Enum\RequestLinkType;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Request-link management and access flow. */
final class RequestLinkFlowTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, array $privileges, ?string $role = null): User
    {
        $group = new Group('G '.$name, 'g-'.$name.'-'.bin2hex(random_bytes(2)), $role);
        foreach ($privileges as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    public function testManagerCreatesLinkAndUserFollowsIt(): void
    {
        $manager = $this->makeUser('mgr', ['manageshifts:view', 'shift:manage', 'invite:manage'], 'ROLE_STAFF');
        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);
        $this->em->flush();

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/manage-shifts/links?department='.$dept->getUuid());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/manage-shifts/links/create', [
            '_token' => $token,
            'department' => $dept->getUuid(),
            'type' => 'availability_request',
        ]);
        self::assertResponseRedirects();

        $link = $this->em->getRepository(RequestLink::class)->findOneBy(['department' => $dept]);
        self::assertNotNull($link);
        self::assertSame(RequestLinkType::AVAILABILITY_REQUEST, $link->getType());

        // A logged-in user following an availability link lands on the grid.
        $volunteer = $this->makeUser('vol', []);
        $this->em->flush();
        $this->client->loginUser($volunteer);
        $this->client->request('GET', '/link/'.$link->getToken());
        self::assertResponseRedirects('/availability');
    }

    public function testInvalidTokenReturnsGone(): void
    {
        $user = $this->makeUser('u', []);
        $this->em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/link/'.str_repeat('a', 48));
        self::assertResponseStatusCodeSame(410);
    }

    public function testManagementRequiresPrivilege(): void
    {
        $user = $this->makeUser('plain', ['shift:view'], 'ROLE_STAFF');
        $this->em->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/manage-shifts/links');
        self::assertResponseStatusCodeSame(403);
    }
}
