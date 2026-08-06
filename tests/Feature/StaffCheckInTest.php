<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Settings;
use App\Entity\Shift;
use App\Entity\State;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Service\EventConfigStore;
use App\Service\Shift\CheckInPolicy;
use App\Tests\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Staff are checked in by finishing onboarding. Without it CheckInPolicy refuses every main-event
 * shift application, which left the whole staff unable to sign up for anything.
 */
final class StaffCheckInTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, ?string $role): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setSettings(new Settings($user));
        $user->addGroup($group);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function walkTheWizard(User $user): void
    {
        $this->client->loginUser($user);
        $this->client->request('POST', '/onboarding', ['consent' => '1']);
        $this->client->request('POST', '/onboarding/profile', ['pronoun' => 'they/them', 'mobile' => '12345']);
        $this->client->request('POST', '/onboarding/telegram');
        $this->client->request('POST', '/onboarding/notifications', ['email_shifts' => '1', 'show_name' => '1', 'show_email' => '1']);
        $this->client->request('POST', '/onboarding/finish', ['password' => 'newpassword1', 'password_confirm' => 'newpassword1']);
    }

    private function runBackfill(array $input = []): CommandTester
    {
        $command = (new Application(static::$kernel))->find('app:onboarding:checkin-staff');
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function reload(User $user): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($user->getId());
    }

    public function testFinishingOnboardingChecksStaffIn(): void
    {
        $this->em->persist(new VolunteerType('Staff'));
        $this->em->flush();

        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $this->walkTheWizard($staff);

        $reloaded = $this->reload($staff);
        self::assertTrue($reloaded->isOnboardingCompleted());
        self::assertTrue($reloaded->getState()?->isArrived());
        self::assertEquals(
            $reloaded->getOnboardingCompletedAt(),
            $reloaded->getState()?->getArrivalDate(),
            'the arrival date is the moment onboarding was completed',
        );
    }

    public function testFinishingOnboardingLeavesVolunteersToCheckInOnArrival(): void
    {
        $this->em->persist(new VolunteerType('Volunteer'));
        $this->em->persist(new Group('Volunteer', 'volunteer', null));
        $this->em->flush();

        $volunteer = $this->makeUser('vol', null);
        $this->walkTheWizard($volunteer);

        $reloaded = $this->reload($volunteer);
        self::assertTrue($reloaded->isOnboardingCompleted());
        self::assertFalse($reloaded->getState()?->isArrived() ?? false);
    }

    public function testBackfillChecksInStaffWhoOnboardedEarlier(): void
    {
        $staff = $this->makeUser('early', 'ROLE_STAFF');
        $staff->completeOnboarding();
        $this->em->flush();

        $this->runBackfill();

        $reloaded = $this->reload($staff);
        self::assertTrue($reloaded->getState()?->isArrived());
        self::assertEquals($reloaded->getOnboardingCompletedAt(), $reloaded->getState()?->getArrivalDate());
    }

    public function testBackfillSkipsVolunteersAndAccountsStillInOnboarding(): void
    {
        $volunteer = $this->makeUser('vol', null);
        $volunteer->completeOnboarding();
        $unfinished = $this->makeUser('pending', 'ROLE_STAFF');
        $this->em->flush();

        $this->runBackfill();

        $this->em->clear();
        $users = $this->em->getRepository(User::class);
        self::assertNull($users->find($volunteer->getId())->getState());
        self::assertNull($users->find($unfinished->getId())->getState());
    }

    /**
     * The Info Desk record of a real arrival is the more accurate of the two and must survive a
     * re-run of the backfill.
     */
    public function testBackfillNeverOverwritesAnExistingCheckIn(): void
    {
        $staff = $this->makeUser('arrived', 'ROLE_STAFF');
        $staff->completeOnboarding();
        $arrivedAt = new \DateTimeImmutable('2026-08-01 09:00:00');
        $state = (new State($staff))->setArrived(true)->setArrivalDate($arrivedAt);
        $staff->setState($state);
        $this->em->persist($state);
        $this->em->flush();

        $this->runBackfill();
        $this->runBackfill();

        self::assertEquals($arrivedAt, $this->reload($staff)->getState()?->getArrivalDate());
    }

    public function testOnboardingClearsTheCheckInGateOnAMainEventShift(): void
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_EVENT_START, '2026-06-05 00:00:00');
        $config->set(EventConfigStore::KEY_EVENT_END, '2026-06-08 00:00:00');

        $department = new Department('Ops', 'ops');
        $shift = (new Shift())->setTitle('Door')
            ->setStartsAt(new \DateTimeImmutable('2026-06-06 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-06 12:00'))
            ->setDepartment($department);
        $this->em->persist($department);
        $this->em->persist($shift);
        $this->em->persist(new VolunteerType('Staff'));
        $this->em->flush();

        $staff = $this->makeUser('worker', 'ROLE_STAFF');
        $policy = static::getContainer()->get(CheckInPolicy::class);
        self::assertNotNull($policy->checkInError($shift, $staff), 'the gate blocks a staff member who has not onboarded');

        $this->walkTheWizard($staff);

        self::assertNull($policy->checkInError($shift, $this->reload($staff)));
    }

    public function testDryRunWritesNothing(): void
    {
        $staff = $this->makeUser('early', 'ROLE_STAFF');
        $staff->completeOnboarding();
        $this->em->flush();

        $tester = $this->runBackfill(['--dry-run' => true]);

        self::assertStringContainsString('1 staff account(s) would be checked in', $tester->getDisplay());
        self::assertNull($this->reload($staff)->getState());
    }
}
