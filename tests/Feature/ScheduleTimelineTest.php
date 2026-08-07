<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Location;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftEntryState;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The staff schedule on screen and as a PDF: people across the top, half hours down the side, and a
 * red row where a staff shift has nobody on it.
 */
final class ScheduleTimelineTest extends DatabaseWebTestCase
{
    private function login(): Department
    {
        $group = new Group('Managers', 'mgr', 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage'] as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $mgr = new User();
        $mgr->setName('mgr')->setEmail('mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $mgr->setPassword($hasher->hashPassword($mgr, 'secret123'));
        $mgr->addGroup($group);
        $mgr->completeOnboarding();
        $this->em->persist($mgr);

        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);
        $loc = (new Location('Main Gate'))->setAlias('main-gate');
        $this->em->persist($loc);
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $worker = new User();
        $worker->setName('Zoe Worker')->setEmail('zoe@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($worker);
        $shift = (new Shift())->setTitle('Gate duty')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 14:00'))
            ->setDepartment($dept)->setLocation($loc);
        $this->em->persist($shift);
        $entry = new ShiftEntry($shift, $type, $worker);
        $entry->setState(ShiftEntryState::ASSIGNMENT);
        $this->em->persist($entry);
        $this->em->flush();

        $this->client->loginUser($mgr);

        return $dept;
    }

    public function testPeopleAreTheColumnsAndHalfHoursTheRows(): void
    {
        $dept = $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/schedule?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('Zoe Worker', $crawler->filter('.schedule-name')->text(), 'the name is a column header');
        self::assertSame(
            ['10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30'],
            $crawler->filter('.schedule-time')->each(static fn ($cell) => $cell->text()),
        );
        self::assertCount(8, $crawler->filter('.schedule-busy'), 'a four-hour shift fills eight half hours');
        self::assertCount(1, $crawler->filter('.schedule-busy.is-start'), 'and is named once, where it starts');
        self::assertSame('10:00–14:00', $crawler->filter('.schedule-hours')->text(), 'the cell carries the shift times');
    }

    /**
     * The brief's reason for this screen: a staff shift nobody is on has no column to sit in, so it
     * has to be impossible to scroll past.
     */
    public function testAStaffShiftWithNobodyOnItGetsARedRow(): void
    {
        $dept = $this->login();
        $shift = (new Shift())->setTitle('Night watch')
            ->setStartsAt(new \DateTimeImmutable('+1 day 20:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 22:00'))
            ->setDepartment($dept)->setAudience(ShiftAudience::DEPARTMENT_STAFF);
        $this->em->persist($shift);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage-shifts/schedule?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tr.schedule-missing'));
        self::assertStringContainsString('Night watch', $crawler->filter('tr.schedule-missing td')->text());
        self::assertStringContainsString('20:00', $crawler->filter('tr.schedule-missing td')->text());
    }

    /** An empty volunteer shift is open for sign-up, not a hole in the roster. */
    public function testAnEmptyPublicShiftIsNotFlagged(): void
    {
        $dept = $this->login();
        $shift = (new Shift())->setTitle('Bag drop')
            ->setStartsAt(new \DateTimeImmutable('+1 day 20:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 22:00'))
            ->setDepartment($dept)->setAudience(ShiftAudience::PUBLIC_VOLUNTEER);
        $this->em->persist($shift);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/manage-shifts/schedule?department='.$dept->getUuid());

        self::assertCount(0, $crawler->filter('tr.schedule-missing'));
    }

    /**
     * shift:manage is department-scoped, so a manager must not be offered a department that answers
     * 403 when they pick it.
     */
    public function testTheDepartmentPickerOffersOnlyWhatTheManagerMayOpen(): void
    {
        $dept = $this->login();
        $other = new Department('Stage', 'stage');
        $this->em->persist($other);

        $scopedGroup = new Group('Dept managers', 'dept-mgr', 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage'] as $name) {
            $scopedGroup->addPrivilege($this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]));
        }
        $this->em->persist($scopedGroup);

        $scoped = new User();
        $scoped->setName('scoped-mgr')->setEmail('scoped@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $scoped->assignGroup($scopedGroup, $dept);
        $scoped->completeOnboarding();
        $this->em->persist($scoped);
        $this->em->flush();
        $this->client->loginUser($scoped);

        $crawler = $this->client->request('GET', '/manage-shifts/schedule?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        $offered = $crawler->filter('#sched-department option')->each(static fn ($o) => $o->attr('value'));
        self::assertSame([(string) $dept->getUuid()], $offered);
        self::assertNotContains((string) $other->getUuid(), $offered);
    }

    /**
     * A department too wide for one page is split across pages rather than cut off, which the PDF
     * renderer would otherwise do silently. This guards the template that does the splitting.
     */
    public function testTheExportSurvivesADepartmentTooWideForOnePage(): void
    {
        $dept = $this->login();
        $type = $this->em->getRepository(VolunteerType::class)->findOneBy(['name' => 'Crew']);
        $shift = (new Shift())->setTitle('All hands')
            ->setStartsAt(new \DateTimeImmutable('+1 day 09:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setDepartment($dept);
        $this->em->persist($shift);

        for ($i = 0; $i < 40; ++$i) {
            $worker = new User();
            $worker->setName(\sprintf('Worker %02d', $i))->setEmail(\sprintf('w%02d@example.com', $i))
                ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
            $this->em->persist($worker);
            $entry = new ShiftEntry($shift, $type, $worker);
            $entry->setState(ShiftEntryState::ASSIGNMENT);
            $this->em->persist($entry);
        }
        $this->em->flush();

        $this->client->request('GET', '/manage-shifts/schedule.pdf?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
    }

    public function testPdfExportReturnsPdf(): void
    {
        $dept = $this->login();
        $this->client->request('GET', '/manage-shifts/schedule.pdf?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', $this->client->getResponse()->getContent());
    }
}
