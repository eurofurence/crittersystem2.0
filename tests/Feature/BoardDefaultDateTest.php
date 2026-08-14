<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Service\DisplaySettings;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Which day the board opens on.
 *
 * A manager who opens the board a week before their department's shifts begin has to land on the
 * first day that has any, not on an empty screen they would have to page forward through.
 */
final class BoardDefaultDateTest extends DatabaseWebTestCase
{
    private function loginFor(Department $department): void
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'board:view']) ?? new Privilege('board:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $department));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function department(): Department
    {
        $department = new Department('Logistics', 'logistics-'.bin2hex(random_bytes(3)));
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    private function shift(Department $department, string $starts, string $ends): void
    {
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable($starts))
            ->setEndsAt(new \DateTimeImmutable($ends))
            ->setDepartment($department);
        $this->em->persist($shift);
        $this->em->flush();
    }

    private function today(): string
    {
        $tz = static::getContainer()->get(DisplaySettings::class)->timezone();

        return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
    }

    public function testDefaultsToTodayWhenTheDepartmentHasShiftsToday(): void
    {
        $department = $this->department();
        $this->shift($department, 'today 10:00', 'today 12:00');
        $this->shift($department, '+5 days 10:00', '+5 days 12:00');
        $this->loginFor($department);

        $this->client->request('GET', '/board/'.$department->getUuid());

        self::assertResponseRedirects('/board/'.$department->getUuid().'/'.$this->today());
    }

    public function testDefaultsToTheFirstFutureDayWhenNothingRunsToday(): void
    {
        $department = $this->department();
        $this->shift($department, '+5 days 10:00', '+5 days 12:00');
        $this->loginFor($department);

        $expected = (new \DateTimeImmutable('+5 days'))
            ->setTimezone(static::getContainer()->get(DisplaySettings::class)->timezone())
            ->format('Y-m-d');

        $this->client->request('GET', '/board/'.$department->getUuid());

        self::assertResponseRedirects('/board/'.$department->getUuid().'/'.$expected);
    }

    public function testDefaultsToTodayWhenTheDepartmentHasNoShiftsAtAll(): void
    {
        $department = $this->department();
        $this->loginFor($department);

        $this->client->request('GET', '/board/'.$department->getUuid());

        self::assertResponseRedirects('/board/'.$department->getUuid().'/'.$this->today());
    }

    /**
     * A shift that began yesterday and is still running counts as today's: filtering on start time
     * would send the morning board to yesterday and hide the shift that is actually in progress.
     */
    public function testOvernightShiftStillRunningCountsAsToday(): void
    {
        $department = $this->department();
        $this->shift($department, 'yesterday 22:00', '+3 hours');
        $this->loginFor($department);

        $this->client->request('GET', '/board/'.$department->getUuid());

        self::assertResponseRedirects('/board/'.$department->getUuid().'/'.$this->today());
    }
}
