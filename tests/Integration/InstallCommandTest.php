<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\User;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallCommandTest extends DatabaseTestCase
{
    private function runInstall(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:install'));
        $tester->execute(['--admin-password' => 'changeme123']);
        $tester->assertCommandIsSuccessful();
    }

    public function testSeedsTenGroupsWithFixedIdsAndDefaultAdmin(): void
    {
        $this->runInstall();
        $this->em->clear();

        $groupRepository = $this->em->getRepository(Group::class);
        self::assertCount(10, $groupRepository->findAll());
        self::assertNotNull($groupRepository->find(10));
        self::assertNotNull($groupRepository->find(90));

        $users = $this->em->getRepository(User::class)->findAll();
        self::assertCount(1, $users);
        self::assertSame('admin', $users[0]->getUserIdentifier());
        self::assertTrue($users[0]->hasPrivilege('admin'));
        self::assertContains('ROLE_ADMIN', $users[0]->getRoles());
    }

    public function testRunningTwiceIsIdempotent(): void
    {
        $this->runInstall();
        $this->runInstall();
        $this->em->clear();

        self::assertCount(10, $this->em->getRepository(Group::class)->findAll());
        self::assertCount(1, $this->em->getRepository(User::class)->findAll());
    }
}
