<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageWorklogTest extends DatabaseWebTestCase
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

    public function testNewPreselectsTheVolunteerFromTheQuery(): void
    {
        $subject = $this->makeUser('subject', ['shift:view']);
        $this->client->loginUser($this->makeUser('boss', ['user:worklog:edit'], 'ROLE_STAFF'));

        $crawler = $this->client->request('GET', '/manage/worklogs/new?user='.$subject->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame((string) $subject->getUuid(), $crawler->filter('input[name="worklog[user]"]')->attr('value'));
        self::assertStringContainsString('subject', $crawler->filter('[data-user-select-target="chips"]')->text());
    }

    public function testNewFallsBackToTheActorWithoutAQuery(): void
    {
        $actor = $this->makeUser('boss', ['user:worklog:edit'], 'ROLE_STAFF');
        $this->client->loginUser($actor);

        $crawler = $this->client->request('GET', '/manage/worklogs/new');

        self::assertSame((string) $actor->getUuid(), $crawler->filter('input[name="worklog[user]"]')->attr('value'));
    }

    /**
     * A uuid that resolves to nobody must not quietly open the form on the signed-in manager:
     * the hours would land on the wrong person.
     */
    public function testNewRejectsAnUnknownVolunteer(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['user:worklog:edit'], 'ROLE_STAFF'));

        $this->client->request('GET', '/manage/worklogs/new?user=6ba7b810-9dad-41d1-80b4-00c04fd430c8');
        self::assertResponseStatusCodeSame(404);
    }

    public function testProfileOffersTheManageFormForTheProfileOwner(): void
    {
        $subject = $this->makeUser('subject', ['shift:view']);
        $this->client->loginUser($this->makeUser('boss', ['user:worklog:edit'], 'ROLE_STAFF'));

        $crawler = $this->client->request('GET', '/users/'.$subject->getUuid());

        self::assertSame(
            '/manage/worklogs/new?user='.$subject->getUuid(),
            $crawler->filter('a[href*="/manage/worklogs/new"]')->attr('href'),
        );
    }

    public function testProfileHidesTheManageFormWithoutThePrivilege(): void
    {
        $staff = $this->makeUser('boss', ['shift:manage'], 'ROLE_STAFF');
        $subject = $this->makeUser('subject', ['shift:view']);
        $this->client->loginUser($staff);

        $crawler = $this->client->request('GET', '/users/'.$subject->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href*="/manage/worklogs/new"]'));
    }
}
