<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftEntryState;
use App\Enum\ShiftState;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * How a shift is named wherever one is drawn.
 *
 * The rule the browse list already followed: the title leads, and the task follows it as a second
 * line. The grids led with the task instead, so a department running one task all weekend drew a
 * column of blocks that all read the same word and could not be told apart - which is what the
 * complaint was. The task is dropped when it only repeats the title, because a shift painted in the
 * planner takes its task's name as its title.
 */
final class ShiftLabelTest extends DatabaseWebTestCase
{
    /** @param string[] $privileges */
    private function user(array $privileges, string $name = 'staffer'): User
    {
        $group = new Group('Group '.$name, 'group-'.$name.'-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach ($privileges as $privilegeName) {
            $privilege = new Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    private function department(string $name = 'Logistics'): Department
    {
        $department = new Department($name, strtolower($name).'-'.bin2hex(random_bytes(2)));
        $this->em->persist($department);

        return $department;
    }

    private function task(string $name, Department $department): ShiftTask
    {
        $task = new ShiftTask($name);
        $task->setDepartment($department);
        $this->em->persist($task);

        return $task;
    }

    private function staffShift(Department $department, string $title, ?ShiftTask $task, VolunteerType $type): Shift
    {
        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($department)
            ->setShiftTask($task)
            ->setAudience(ShiftAudience::ALL_STAFF)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);

        return $shift;
    }

    private function confirmedMember(User $user, VolunteerType $type): void
    {
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
    }

    public function testTheApplyGridBlockLeadsWithTheTitleAndFollowsWithTheTask(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user(['manageshifts:view', 'shift:self']);
        $this->confirmedMember($user, $type);
        $department = $this->department();
        $this->staffShift($department, 'North gate', $this->task('Security', $department), $type);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/apply?scope=all');

        self::assertResponseIsSuccessful();
        self::assertSame('North gate', $crawler->filter('.apply-block-title')->text());
        self::assertSame('Security', $crawler->filter('.apply-block-task')->text());
        self::assertStringContainsString('North gate', (string) $crawler->filter('.apply-block')->attr('title'));
    }

    public function testTheApplyGridDropsATaskThatOnlyRepeatsTheTitle(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user(['manageshifts:view', 'shift:self']);
        $this->confirmedMember($user, $type);
        $department = $this->department();
        $this->staffShift($department, 'Security', $this->task('Security', $department), $type);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/apply?scope=all');

        self::assertResponseIsSuccessful();
        self::assertSame('Security', $crawler->filter('.apply-block-title')->text());
        self::assertCount(0, $crawler->filter('.apply-block-task'));
    }

    public function testThePlannerBlockLeadsWithTheTitleAndFollowsWithTheTask(): void
    {
        $user = $this->user(['manageshifts:view', 'shift:manage'], 'mgr');
        $department = $this->department();
        $shift = (new Shift())->setTitle('North gate')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 22:00', new \DateTimeZone('UTC')))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 23:30', new \DateTimeZone('UTC')))
            ->setState(ShiftState::DRAFT)
            ->setShiftTask($this->task('Security', $department))
            ->setDepartment($department);
        $this->em->persist($shift);

        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_BUILDUP_START, '2026-06-01T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_START, '2026-06-01T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_END, '2026-06-02T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_TEARDOWN_END, '2026-06-02T00:00:00+00:00');
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$department->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('North gate', $crawler->filter('.planner-block-title')->text());
        self::assertSame('Security', $crawler->filter('.planner-block-task')->text());
        self::assertStringContainsString('North gate', (string) $crawler->filter('.planner-block')->attr('title'));
    }

    public function testTheScheduleCellNamesTheShiftAndNotOnlyItsTask(): void
    {
        $user = $this->user(['manageshifts:view', 'shift:manage'], 'mgr');
        $department = $this->department();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);

        $worker = new User();
        $worker->setName('Zoe Worker')->setEmail('zoe@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($worker);

        $shift = $this->staffShift($department, 'North gate', $this->task('Security', $department), $type);
        $entry = new ShiftEntry($shift, $type, $worker);
        $entry->setState(ShiftEntryState::ASSIGNMENT);
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/schedule?department='.$department->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('North gate', $crawler->filter('.schedule-title')->text());
        self::assertSame('Security', $crawler->filter('.schedule-task')->text());
    }

    /** The list a volunteer checks before they set off has to say which shift it is talking about. */
    public function testMyShiftsNamesTheTaskUnderTheTitle(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user(['shift:view', 'shift:self']);
        $department = $this->department();
        $shift = $this->staffShift($department, 'North gate', $this->task('Security', $department), $type);
        $entry = new ShiftEntry($shift, $type, $user);
        $entry->setState(ShiftEntryState::ASSIGNMENT);
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/my-shifts');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('North gate', $crawler->filter('tbody tr')->text());
        self::assertStringContainsString('Security', $crawler->filter('tbody tr')->text());
    }
}
