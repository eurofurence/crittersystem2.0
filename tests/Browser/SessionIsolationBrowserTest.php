<?php

namespace App\Tests\Browser;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every browser test must start signed out and be able to sign in as its own user.
 *
 * One Chrome serves the whole run while each test truncates the schema, so the browser carries its
 * session cookie into a test whose fixtures no longer exist, and nothing deletes the server-side
 * session it points at. Symfony currently breaks the chain on its own - the reloaded user's password
 * hash differs, so the token is discarded as changed - but that is a property of the framework
 * rather than of anything here, and it stops holding the moment a test seeds a fixed hash.
 *
 * Both tests seed the SAME username deliberately. With a random one per test the identifiers differ
 * and any carried token is dropped for that reason alone, which would make this pass without
 * testing anything.
 */
final class SessionIsolationBrowserTest extends BrowserTestCase
{
    private const SHARED_USERNAME = 'isolation-probe';

    private function volunteer(): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, 'ROLE_USER');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'news:view']) ?? new Privilege('news:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName(self::SHARED_USERNAME)->setEmail(self::SHARED_USERNAME.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signInAsTheSharedUser(): void
    {
        $user = $this->volunteer();

        $this->browse();

        $this->client->request('GET', '/dashboard');
        $this->client->waitFor('body', 10);
        self::assertStringContainsString(
            '/login',
            $this->client->getCurrentURL(),
            'this test began already authenticated: a session outlived the row it points at',
        );

        $this->signIn($user, 'secret123');

        $this->client->request('GET', '/dashboard');
        $this->client->waitFor('body', 10);
        self::assertStringNotContainsString('/login', $this->client->getCurrentURL(), 'signing in did not take effect');
    }

    public function testTheFirstTestStartsSignedOut(): void
    {
        $this->signInAsTheSharedUser();
    }

    public function testTheSecondTestAlsoStartsSignedOut(): void
    {
        $this->signInAsTheSharedUser();
    }
}
