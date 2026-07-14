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
use App\Enum\ShiftEntryState;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Staff schedule timeline interactive view and PDF export. */
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
        $loc = new Location('Main Gate');
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

    public function testInteractiveTimelineShowsAssignedUser(): void
    {
        $dept = $this->login();
        $this->client->request('GET', '/manage-shifts/schedule?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Zoe Worker');
        self::assertSelectorExists('.schedule-block');
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
