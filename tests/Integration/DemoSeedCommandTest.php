<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Enum\ShiftState;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the demo seeder behind the introduction slide deck (docs/slides).
 *
 * The properties asserted here are the ones whose failure is silent: a seeder that produced shifts
 * in the past, or volunteers with no confirmed critter type, still exits successfully and still
 * lets the screenshot run finish. It just quietly photographs empty pages.
 */
final class DemoSeedCommandTest extends DatabaseTestCase
{
    private function seed(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:demo:seed'));
        $tester->execute(['--password' => 'demo1234']);

        return $tester;
    }

    public function testSeedsAWorkingDemoEvent(): void
    {
        $this->seed()->assertCommandIsSuccessful();
        $this->em->clear();

        $departments = $this->em->getRepository(Department::class);
        foreach (['demo-info-desk', 'demo-security', 'demo-stage-tech', 'demo-registration', 'demo-artist-alley', 'demo-volunteer-care'] as $slug) {
            self::assertNotNull($departments->findOneBy(['slug' => $slug]), "the demo department \"{$slug}\" must exist");
        }
        self::assertGreaterThan(40, \count($this->em->getRepository(User::class)->findAll()));
        self::assertGreaterThan(50, \count($this->em->getRepository(Shift::class)->findAll()));
    }

    /** Each of the four walkthrough accounts must exist and be able to sign in. */
    public function testCreatesTheFourArchetypeAccounts(): void
    {
        $this->seed();
        $this->em->clear();

        $users = $this->em->getRepository(User::class);
        foreach (['admin', 'morgan', 'sparky', 'rowan'] as $username) {
            $user = $users->findOneBy(['name' => $username]);
            self::assertNotNull($user, "the demo account \"{$username}\" must exist");
            self::assertTrue($user->isOnboardingCompleted(), "\"{$username}\" must be past onboarding");
        }

        self::assertTrue($users->findOneBy(['name' => 'admin'])->hasPrivilege('global:admin'));
        self::assertTrue($users->findOneBy(['name' => 'sparky'])->hasPrivilege('shift:manage'));
    }

    /**
     * The deck's volunteer chapter is a tour of pages that are empty when every shift has already
     * happened. Seeding relative to "tomorrow" is what keeps them populated whenever the
     * screenshots are retaken.
     */
    public function testEveryPublishedShiftIsInTheFuture(): void
    {
        $this->seed();
        $this->em->clear();

        $now = new \DateTimeImmutable();
        foreach ($this->em->getRepository(Shift::class)->findAll() as $shift) {
            self::assertGreaterThan(
                $now,
                $shift->getEndsAt(),
                \sprintf('shift "%s" is already over, so it would not appear anywhere', $shift->getTitle()),
            );
        }
    }

    /**
     * A volunteer with no confirmed critter-type membership can browse shifts but never sign up,
     * which would make the sign-up screenshot impossible to take.
     */
    public function testRowanHasAConfirmedMembershipToSignUpWith(): void
    {
        $this->seed();
        $this->em->clear();

        $rowan = $this->em->getRepository(User::class)->findOneBy(['name' => 'rowan']);
        $memberships = $this->em->getRepository(UserVolunteerType::class)->findBy(['user' => $rowan]);

        self::assertNotEmpty($memberships, 'rowan must belong to at least one critter type');
        self::assertNotEmpty(
            array_filter($memberships, static fn (UserVolunteerType $m): bool => $m->isConfirmed()),
            'at least one of rowan\'s memberships must be confirmed, or nothing can be signed up for',
        );
    }

    /** The deck shows what a draft looks like, so the seed has to contain some. */
    public function testSeedsBothDraftAndPublishedShifts(): void
    {
        $this->seed();
        $this->em->clear();

        $shifts = $this->em->getRepository(Shift::class);
        self::assertNotEmpty($shifts->findBy(['state' => ShiftState::DRAFT]));
        self::assertNotEmpty($shifts->findBy(['state' => ShiftState::PUBLISHED]));
    }

    /**
     * Names are unique, so a second run collides part-way and leaves a half-built event behind.
     * Refusing up front is what makes "recreate the database" the only supported path.
     */
    public function testRefusesToSeedOnTopOfExistingAccounts(): void
    {
        $this->seed()->assertCommandIsSuccessful();
        $this->em->clear();

        $second = $this->seed();

        self::assertSame(Command::FAILURE, $second->getStatusCode());
        self::assertStringContainsString('already holds accounts', $second->getDisplay());
    }
}
