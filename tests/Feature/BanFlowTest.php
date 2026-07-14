<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Gdpr\BanChecker;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BanFlowTest extends DatabaseWebTestCase
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

    private function bans(): BanChecker
    {
        return static::getContainer()->get(BanChecker::class);
    }

    public function testBannedUserCannotLogIn(): void
    {
        $user = $this->makeUser('locked');
        $this->bans()->banUser($user, 'No-show threshold', true, 2);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', [
            '_username' => 'locked',
            '_password' => 'secret123',
            '_csrf_token' => $token,
        ]);

        // Authentication is refused -> back to the login page.
        self::assertResponseRedirects('/login');
    }

    public function testNonBannedUserCanLogIn(): void
    {
        $this->makeUser('okuser');

        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', [
            '_username' => 'okuser',
            '_password' => 'secret123',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', $this->client->getResponse()->headers->get('Location') ?? '');
    }

    public function testAdminLiftsBehaviouralBanAndResetsCounter(): void
    {
        $subject = $this->makeUser('offender');
        $this->bans()->banUser($subject, 'No-show threshold', true, 2);
        $this->em->flush();
        self::assertTrue($this->bans()->isUserBanned($subject));

        $admin = $this->makeUser('mod', ['user:delete']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/manage/bans');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');

        // Extract the ban id from the first lift form action (…/manage/bans/{id}/lift).
        preg_match('#/manage/bans/([0-9a-f-]{36})/lift#', $crawler->filter('form[action*="/lift"]')->first()->attr('action'), $m);
        $this->client->request('POST', '/manage/bans/'.$m[1].'/lift', ['_token' => $token]);
        self::assertResponseRedirects('/manage/bans');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($subject->getId());
        self::assertFalse($this->bans()->isUserBanned($reloaded));
        self::assertNotNull($reloaded->getNoShowBaselineAt());
    }
}
