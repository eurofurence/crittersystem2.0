<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\DutyRecord;
use App\Entity\Group;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The live duty board names the operational departments nobody is covering.
 *
 * The warning is only worth reading if everything in it is actionable, so two kinds of entry stay
 * out: organizational departments, where nobody is ever rostered on duty and which would therefore
 * sit in the list permanently, and the case of nobody being on duty at all, which the empty state
 * already states and which would otherwise name every operational department at once.
 */
final class StaffLiveUnderstaffedTest extends DatabaseWebTestCase
{
    private function staff(): User
    {
        $group = new Group('Live staff', 'livestaff-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('live-'.bin2hex(random_bytes(3)))->setEmail('live-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function department(string $name, bool $organizational = false): Department
    {
        $department = new Department($name, strtolower($name).'-'.bin2hex(random_bytes(3)));
        $department->setOrganizational($organizational);
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    /** An open duty record, which is what "on duty" means here. */
    private function onDuty(Department $department): void
    {
        $user = new User();
        $user->setName('duty-'.bin2hex(random_bytes(3)))->setEmail('duty-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->persist(new DutyRecord($user, $department));
        $this->em->flush();
    }

    public function testADepartmentWithNobodyOnDutyIsNamed(): void
    {
        $this->staff();
        $covered = $this->department('Covered');
        $bare = $this->department('Bare');
        $this->onDuty($covered);

        $crawler = $this->client->request('GET', '/staff/live');

        self::assertResponseIsSuccessful();
        $alert = $crawler->filter('.alert-warning');
        self::assertSame(1, $alert->count());
        self::assertStringContainsString($bare->getName(), $alert->text());
    }

    public function testADepartmentThatIsCoveredIsNotNamed(): void
    {
        $this->staff();
        $covered = $this->department('Covered');
        $this->department('Bare');
        $this->onDuty($covered);

        $crawler = $this->client->request('GET', '/staff/live');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($covered->getName(), $crawler->filter('.alert-warning')->text());
    }

    /**
     * A bare operational department is seeded alongside the organizational one so the warning is
     * rendering at all, which makes the absence of the organizational name mean something.
     */
    public function testAnOrganizationalDepartmentIsNeverNamed(): void
    {
        $this->staff();
        $covered = $this->department('Covered');
        $bare = $this->department('Bare');
        $board = $this->department('Steering', true);
        $this->onDuty($covered);

        $crawler = $this->client->request('GET', '/staff/live');

        self::assertResponseIsSuccessful();
        $alert = $crawler->filter('.alert-warning');

        self::assertStringContainsString($bare->getName(), $alert->text());
        self::assertStringNotContainsString(
            $board->getName(),
            $alert->text(),
            'nobody is ever on duty in an organizational department, so it would warn forever'
        );
    }

    public function testWithNobodyOnDutyTheEmptyStateStandsAlone(): void
    {
        $this->staff();
        $this->department('Bare one');
        $this->department('Bare two');

        $crawler = $this->client->request('GET', '/staff/live');

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $crawler->filter('.alert-warning')->count(),
            'the empty state already says nobody is on duty; listing every department repeats it'
        );
    }
}
