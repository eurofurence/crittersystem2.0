<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Publish endpoint: publishing flips a department's drafts live. */
final class PlannerPublishTest extends DatabaseWebTestCase
{
    /** @param string[] $privileges */
    private function login(array $privileges): Department
    {
        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach ($privileges as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('mgr')->setEmail('mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);
        $task = new ShiftTask('General');
        $this->em->persist($task);
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($dept)->setShiftTask($task)->setState(ShiftState::DRAFT);
        $this->em->persist($shift);
        $this->em->flush();

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));

        return $dept;
    }

    private function publishToken(): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/planner');

        return $crawler->filter('form[action*="/publish"] input[name="_token"]')->attr('value');
    }

    public function testManagerCanPublishDrafts(): void
    {
        $dept = $this->login(['manageshifts:view', 'shift:manage', 'shift:publish']);
        $token = $this->publishToken();

        $this->client->request('POST', '/manage-shifts/planner/publish', [
            '_token' => $token,
            'department' => $dept->getUuid(),
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertSame(1, $payload['published']);

        $this->em->clear();
        $shift = $this->em->getRepository(Shift::class)->findAll()[0];
        self::assertSame(ShiftState::PUBLISHED, $shift->getState());
    }

    public function testPublishForbiddenWithoutPrivilege(): void
    {
        $dept = $this->login(['manageshifts:view', 'shift:manage']);
        // Without shift:publish there is no token field to read, so post a dummy.
        $this->client->request('POST', '/manage-shifts/planner/publish', [
            '_token' => 'x',
            'department' => $dept->getUuid(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
