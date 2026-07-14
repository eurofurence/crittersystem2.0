<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\User;
use App\Entity\Worklog;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class WorklogSelfTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, ?string $role = null): User
    {
        $group = new Group(ucfirst($name), $name.'-grp', $role);
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

    private function worklog(User $subject, User $creator): Worklog
    {
        $worklog = (new Worklog($subject))->setCreator($creator)->setHours(2.0)
            ->setWorkedAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($worklog);
        $this->em->flush();

        return $worklog;
    }

    public function testStaffCanAddSelfWorklog(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $this->client->loginUser($staff);

        $crawler = $this->client->request('GET', '/profile');
        $token = $crawler->filter('input[name="worklog_self[_token]"]')->attr('value');

        $this->client->request('POST', '/worklog/self', ['worklog_self' => [
            '_token' => $token,
            'hours' => '3.5',
            'workedAt' => '2026-07-10T09:00',
            'comment' => 'Late night teardown',
        ]]);

        self::assertResponseRedirects('/profile');
        $logs = $this->em->getRepository(Worklog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame($staff->getId(), $logs[0]->getUser()->getId());
        self::assertSame($staff->getId(), $logs[0]->getCreator()->getId());
    }

    public function testStaffCanDeleteOwnSelfWorklog(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $log = $this->worklog($staff, $staff);
        $this->client->loginUser($staff);

        $crawler = $this->client->request('GET', '/profile');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/worklog/'.$log->getUuid().'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/profile');
        self::assertCount(0, $this->em->getRepository(Worklog::class)->findAll());
    }

    public function testStaffCannotEditManagerRecordedWorklog(): void
    {
        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $manager = $this->makeUser('boss', 'ROLE_STAFF');
        $log = $this->worklog($staff, $manager);
        $this->client->loginUser($staff);

        $this->client->request('GET', '/worklog/'.$log->getUuid().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testNonStaffCannotSelfReport(): void
    {
        $volunteer = $this->makeUser('vol');
        $this->client->loginUser($volunteer);

        $this->client->request('POST', '/worklog/self', ['worklog_self' => ['_token' => 'x', 'hours' => '1', 'workedAt' => '2026-07-10T09:00']]);
        self::assertResponseStatusCodeSame(403);
    }
}
