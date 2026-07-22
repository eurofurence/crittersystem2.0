<?php

namespace App\Tests\Support;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Location;
use App\Entity\NeededVolunteerType;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixture builder for the volunteer-facing shift pages (/shifts, /shifts/{id}, sign-up, cancel,
 * /my-shifts).
 *
 * A shift only becomes browsable once it has a department, a location, a task, a volunteer type, a
 * *confirmed* membership and a needed-type row. Miss one of those and the page renders empty, so the
 * test passes while asserting nothing. The setup therefore lives here once and every shift test
 * starts from it.
 */
final class ShiftScenario
{
    public Department $department;
    public Location $location;
    public ShiftTask $task;
    public VolunteerType $type;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        $suffix = bin2hex(random_bytes(3));

        $this->department = new Department('Demo Dept', 'demo-'.$suffix);
        $this->location = (new Location('Demo Location'))->setAlias('demo-location');
        $this->task = new ShiftTask('Demo Task');
        $this->type = new VolunteerType('Demo Crew');

        $this->em->persist($this->department);
        $this->em->persist($this->location);
        $this->em->persist($this->task);
        $this->em->persist($this->type);
        $this->em->flush();
    }

    /**
     * A user with the given privileges. `$memberOf` grants a CONFIRMED membership of a volunteer
     * type - without confirmation the user can browse shifts but can never sign up, which is the
     * single easiest way to write a shift test that passes for the wrong reason.
     *
     * @param string[] $privileges
     */
    public function user(array $privileges = ['shift:view', 'shift:self'], ?VolunteerType $memberOf = null, string $role = 'ROLE_USER'): User
    {
        $suffix = bin2hex(random_bytes(4));

        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, $role);
        foreach ($privileges as $name) {
            // Privileges are global and unique by name - reuse one if a previous user in this
            // scenario already created it, or the second user blows up on the unique index.
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name])
                ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('vol-'.$suffix)
            ->setEmail('vol-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($this->hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        // Telegram-linked by default: the /api/bot surface only ever acts for a
        // linked volunteer, and the acting-user resolver now enforces that.
        $user->linkTelegram('tg-'.$suffix, '@vol-'.$suffix);
        $this->em->persist($user);

        if ($memberOf !== null) {
            $membership = new UserVolunteerType($user, $memberOf);
            $membership->setConfirmedBy($user); // confirmed; an unconfirmed member cannot sign up
            $this->em->persist($membership);
        }

        $this->em->flush();

        return $user;
    }

    /**
     * A published shift that needs `$needed` volunteers of the scenario's type.
     * `$startsAt` accepts any strtotime-able expression ('tomorrow 10:00', '-2 hours', …).
     */
    public function shift(string $title, string $startsAt = 'tomorrow 10:00', string $duration = '+2 hours', int $needed = 2, ShiftState $state = ShiftState::PUBLISHED): Shift
    {
        $start = new \DateTimeImmutable($startsAt);

        $shift = (new Shift())
            ->setTitle($title)
            ->setStartsAt($start)
            ->setEndsAt($start->modify($duration))
            ->setDepartment($this->department)
            ->setLocation($this->location)
            ->setShiftTask($this->task)
            ->setState($state);

        if ($needed > 0) {
            $shift->addNeededVolunteerType(new NeededVolunteerType($this->type, $needed));
        }

        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    /**
     * Make a user a member of the scenario's department.
     *
     * Department membership is a group assignment SCOPED to the department - an unscoped assignment
     * makes someone a member of nothing, which is why they would not appear as an assignable
     * candidate anywhere.
     */
    public function departmentMember(User $user): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Dept '.$suffix, 'dept-'.$suffix, 'ROLE_USER');
        $this->em->persist($group);
        $this->em->persist(new \App\Entity\UserGroupAssignment($user, $group, $this->department));
        $this->em->flush();

        return $user;
    }

    /** Sign a user up for a shift directly, bypassing the HTTP flow. */
    public function signUp(User $user, Shift $shift, ?VolunteerType $type = null): ShiftEntry
    {
        $entry = new ShiftEntry($shift, $type ?? $this->type, $user);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
