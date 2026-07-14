<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftEntryState;
use App\Service\Shift\ScheduleTimelineService;
use App\Tests\DatabaseTestCase;

/** Staff schedule timeline view model. */
final class ScheduleTimelineServiceTest extends DatabaseTestCase
{
    public function testBuildsRowsForAssignedUsers(): void
    {
        $svc = static::getContainer()->get(ScheduleTimelineService::class);
        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);
        $loc = new Location('Main Gate');
        $this->em->persist($loc);
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $worker = new User();
        $worker->setName('Zoe')->setEmail('zoe@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($worker);
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 14:00'))
            ->setDepartment($dept)->setLocation($loc);
        $this->em->persist($shift);
        $entry = new ShiftEntry($shift, $type, $worker);
        $entry->setState(ShiftEntryState::ASSIGNMENT);
        $this->em->persist($entry);
        $this->em->flush();

        $data = $svc->build(
            $dept,
            new \DateTimeImmutable('2026-06-01 00:00'),
            new \DateTimeImmutable('2026-06-03 00:00'),
            new \DateTimeZone('UTC'),
        );

        self::assertCount(1, $data['rows']);
        self::assertSame('Zoe', $data['rows'][0]['user']->getName());
        self::assertCount(1, $data['rows'][0]['blocks']);
        self::assertSame('Main Gate', $data['rows'][0]['blocks'][0]['location']);
    }
}
