<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileControllerTest extends DatabaseWebTestCase
{
    /**
     * @param string[] $privileges
     */
    private function makeUser(string $name, array $privileges = [], ?string $role = null): User
    {
        $group = new Group(ucfirst($name), $name.'-grp', $role);
        foreach ($privileges as $priv) {
            $p = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $priv]) ?? new Privilege($priv);
            $this->em->persist($p);
            $group->addPrivilege($p);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testOwnProfileRenders(): void
    {
        $user = $this->makeUser('vera', ['shift:view']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'vera');
    }

    public function testStaffCanViewAnyProfile(): void
    {
        $subject = $this->makeUser('subject', ['shift:view']);
        $staff = $this->makeUser('boss', [], 'ROLE_STAFF');
        $this->client->loginUser($staff);

        $this->client->request('GET', '/users/'.$subject->getUuid());
        self::assertResponseIsSuccessful();
    }

    public function testNonStaffCannotViewOrdinaryProfile(): void
    {
        $subject = $this->makeUser('other', ['shift:view']);
        $volunteer = $this->makeUser('vol', ['shift:view']);
        $this->client->loginUser($volunteer);

        $this->client->request('GET', '/users/'.$subject->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    public function testNonStaffCanViewManagerProfile(): void
    {
        $manager = $this->makeUser('mgr', ['shift:manage']);
        $volunteer = $this->makeUser('vol', ['shift:view']);
        $this->client->loginUser($volunteer);

        $this->client->request('GET', '/users/'.$manager->getUuid());
        self::assertResponseIsSuccessful();
    }

    public function testAvatarNotFoundWhenNone(): void
    {
        $user = $this->makeUser('noavatar', ['shift:view']);
        $this->client->loginUser($user);

        $this->client->request('GET', '/media/avatar/'.$user->getUuid());
        self::assertResponseStatusCodeSame(404);
    }
}
