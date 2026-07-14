<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DepartmentOrgTest extends DatabaseWebTestCase
{
    private function loginManager(): void
    {
        $group = new Group('Managers', 'mgr-grp', 'ROLE_STAFF');
        $priv = new Privilege('shift:manage');
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('deptmgr')->setEmail('deptmgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function makeDepartment(): Department
    {
        $department = new Department('Stage', 'stage');
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    public function testOrganizationalEditableWhenNoShifts(): void
    {
        $this->loginManager();
        $department = $this->makeDepartment();

        $crawler = $this->client->request('GET', '/manage/departments/'.$department->getUuid().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="department[organizational]"][disabled]'));
    }

    public function testOrganizationalLockedWhenShiftsExist(): void
    {
        $this->loginManager();
        $department = $this->makeDepartment();

        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 2 hours'))
            ->setDepartment($department);
        $this->em->persist($shift);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage/departments/'.$department->getUuid().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="department[organizational]"][disabled]'));
    }
}
