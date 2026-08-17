<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The staff shift application screen.
 *
 * Two rules from the brief are pinned here because breaking either is invisible to a green suite:
 * a volunteer must be told why they cannot apply, in words they can act on, and no button or link
 * may be rendered that answers 403 or 404 when they follow it.
 */
final class StaffApplyScreenTest extends DatabaseWebTestCase
{
    private function staffGroup(): Group
    {
        $group = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:self'] as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        return $group;
    }

    private function user(Group $group, string $name = 'staffer'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    private function shift(Department $department, VolunteerType $type, string $start = '+1 day 10:00', string $end = '+1 day 12:00'): Shift
    {
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($department)
            ->setAudience(ShiftAudience::ALL_STAFF)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);

        return $shift;
    }

    private function department(string $name = 'Ops'): Department
    {
        $department = new Department($name, strtolower($name).'-'.bin2hex(random_bytes(2)));
        $this->em->persist($department);

        return $department;
    }

    public function testTheGridDrawsADepartmentColumnAndTheDayPicker(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user($this->staffGroup());
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $this->shift($this->department('Logistics'), $type);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/apply?scope=all');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.apply-department'));
        self::assertStringContainsString('Logistics', $crawler->filter('.apply-department-head')->text());
        self::assertCount(1, $crawler->filter('#apply-day option'));
        self::assertCount(1, $crawler->filter('.apply-block'));
    }

    /**
     * The brief's rule: never "ineligible" with no further information. A volunteer who is not a
     * member of the role gets told so and gets the way to join it.
     */
    public function testTheDialogNamesTheMissingRoleAndOffersTheWayToJoinIt(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user($this->staffGroup());
        $shift = $this->shift($this->department(), $type);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/apply/shift/'.$shift->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('not a confirmed member', $crawler->filter('.list-group-item')->text());
        self::assertCount(1, $crawler->filter('a[href="/volunteer-types/'.$type->getUuid().'"]'));
        self::assertCount(0, $crawler->filter('form[action^="/manage-shifts/apply/"]'), 'no apply button that could only fail');
    }

    /**
     * A staff-only, department-only role the volunteer cannot reach must not be linked: the target
     * page answers 404, and a dead link is worse than none.
     */
    public function testARoleTheVolunteerMayNotSeeIsNotLinked(): void
    {
        $type = (new VolunteerType('Secret Crew'))->setStaffOnly(true)->setDepartmentOnly(true);
        $this->em->persist($type);
        $this->department('Restricted')->addVolunteerType($type);

        $user = $this->user($this->staffGroup());
        $shift = $this->shift($this->department('Ops'), $type);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/apply/shift/'.$shift->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('not a confirmed member', $crawler->filter('.list-group-item')->text());
        self::assertCount(0, $crawler->filter('a[href="/volunteer-types/'.$type->getUuid().'"]'));
    }

    /** A missing certification is named, and the volunteer is pointed at it. */
    public function testTheDialogLinksTheCertificationTheRoleRequires(): void
    {
        $certification = new Certification('First Aid');
        $this->em->persist($certification);
        $type = new VolunteerType('Medic');
        $type->addCertification($certification);
        $this->em->persist($type);

        $user = $this->user($this->staffGroup());
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $shift = $this->shift($this->department(), $type);
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/manage-shifts/apply/shift/'.$shift->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('First Aid', $crawler->filter('.list-group-item')->text());
        self::assertCount(1, $crawler->filter('a[href="/certifications/'.$certification->getUuid().'"]'));
    }

    /** A shift outside the volunteer's audience must not be confirmed to exist by the dialog. */
    public function testTheDialogIs404ForAShiftTheVolunteerMayNotSee(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user($this->staffGroup());
        $shift = $this->shift($this->department('Tech'), $type)->setAudience(ShiftAudience::DEPARTMENT_STAFF);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/manage-shifts/apply/shift/'.$shift->getUuid());

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The cost of the screen stays flat in the number of shifts and departments on it, which only a
     * query count can hold it to: the shift list is easily asked for once per department, and
     * staffing, eligibility, hours and availability once per shift on top of that.
     *
     * The bound is roughly fifty because it covers the page around the grid as well; the grid
     * itself costs a fixed handful.
     */
    public function testQueryCountDoesNotGrowWithTheNumberOfShifts(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user($this->staffGroup());
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);

        $day = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        for ($d = 0; $d < 6; ++$d) {
            $department = $this->department('Dept'.$d);
            for ($s = 0; $s < 10; ++$s) {
                $this->shift($department, $type, $day.' 10:00', $day.' 12:00');
            }
        }
        $this->em->flush();

        $this->client->loginUser($user);
        $url = '/manage-shifts/apply?scope=all&day='.$day;
        $this->client->request('GET', $url);

        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $queries = $this->client->getProfile()->getCollector('db')->getQueryCount();
        self::assertLessThan(
            55,
            $queries,
            \sprintf('drawing 60 shifts across 6 departments took %d queries; it must stay flat', $queries),
        );
    }

    /** Applying returns the volunteer to the day and departments they were looking at. */
    public function testApplyingKeepsTheFiltersTheVolunteerHadChosen(): void
    {
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $user = $this->user($this->staffGroup());
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $department = $this->department();
        $day = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $shift = $this->shift($department, $type, $day.' 10:00', $day.' 12:00');
        $this->em->flush();

        $this->client->loginUser($user);
        $query = '?day='.$day.'&scope=all&departments%5B0%5D='.$department->getUuid();
        $crawler = $this->client->request('GET', '/manage-shifts/apply/shift/'.$shift->getUuid().$query);
        $this->client->submit($crawler->filter('form[action^="/manage-shifts/apply/"]')->first()->form());

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('day='.$day, $location);
        self::assertStringContainsString('scope=all', $location);
        self::assertStringContainsString((string) $department->getUuid(), $location);
    }
}
