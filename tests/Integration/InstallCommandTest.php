<?php

namespace App\Tests\Integration;

use App\Entity\ConsentText;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\VolunteerType;
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

    public function testSeedsCoreGroupsAndDefaultAdmin(): void
    {
        $this->runInstall();
        $this->em->clear();

        $groupRepository = $this->em->getRepository(Group::class);
        self::assertCount(12, $groupRepository->findAll());
        self::assertNotNull($groupRepository->findOneBy(['slug' => 'global-admin']));
        self::assertNotNull($groupRepository->findOneBy(['slug' => 'sub-admin']));

        $users = $this->em->getRepository(User::class)->findAll();
        self::assertCount(1, $users);
        self::assertSame('admin', $users[0]->getUserIdentifier());
        self::assertTrue($users[0]->hasPrivilege('global:admin'));
        self::assertContains('ROLE_ADMIN', $users[0]->getRoles());
    }

    /** Re-running seeds nothing twice: the consent text in particular is created once, never duplicated. */
    public function testRunningTwiceIsIdempotent(): void
    {
        $this->runInstall();
        $this->runInstall();
        $this->em->clear();

        self::assertCount(12, $this->em->getRepository(Group::class)->findAll());
        self::assertCount(1, $this->em->getRepository(User::class)->findAll());
        self::assertCount(1, $this->em->getRepository(ConsentText::class)->findAll());
    }

    public function testSeedsEnglishConsentTextCoveringCookiesAndDeletion(): void
    {
        $this->runInstall();
        $this->em->clear();

        $consent = $this->em->getRepository(ConsentText::class)->findOneBy(['locale' => 'en_US']);
        self::assertNotNull($consent, 'a fresh install must seed the en_US consent text');

        $label = $consent->getCheckboxLabel();
        self::assertStringContainsStringIgnoringCase('personal data', $label);
        self::assertStringContainsStringIgnoringCase('cookies', $label);
        self::assertStringContainsString('%deletion_days', $label);
    }

    /**
     * Re-running the installer against a renamed base type must adopt it, not seed a second one.
     *
     * The seed used to match on the shipped name, so an event that renamed Volunteer to Critter got
     * a fresh Volunteer alongside it on the next deployment - two base types, and onboarding
     * assigning whichever the lookup happened to find.
     */
    public function testReinstallingAdoptsARenamedBaseTypeInsteadOfDuplicatingIt(): void
    {
        $this->runInstall();
        $this->em->clear();

        $types = $this->em->getRepository(VolunteerType::class);
        $volunteer = $types->findOneBy(['role' => VolunteerType::ROLE_VOLUNTEER]);
        self::assertNotNull($volunteer, 'the installer stamps the role it will match on next time');

        $volunteer->setName('Critter');
        $this->em->flush();
        $this->em->clear();

        $this->runInstall();
        $this->em->clear();

        self::assertCount(1, $types->findBy(['role' => VolunteerType::ROLE_VOLUNTEER]));
        self::assertNull($types->findOneBy(['name' => 'Volunteer']), 'no second base type is seeded beside the renamed one');
        self::assertSame('Critter', $types->findOneBy(['role' => VolunteerType::ROLE_VOLUNTEER])?->getName());
    }

}
