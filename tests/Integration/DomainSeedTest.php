<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\ShiftTask;
use App\Entity\VolunteerType;
use App\Service\Install\Installer;
use App\Tests\DatabaseTestCase;

final class DomainSeedTest extends DatabaseTestCase
{
    private function installer(): Installer
    {
        return static::getContainer()->get(Installer::class);
    }

    /** The seeded volunteer types have to satisfy the flag interdependencies, not merely exist. */
    public function testSeedsGlobalShiftTasksAndVolunteerTypes(): void
    {
        $this->installer()->seedDomainDefaults();
        $this->em->clear();

        $tasks = $this->em->getRepository(ShiftTask::class);
        foreach (['Setup', 'Tear down', 'Helper', 'Runner', 'Certification', 'Assistant', 'Staff Shift'] as $name) {
            $task = $tasks->findOneBy(['name' => $name]);
            self::assertNotNull($task, "Missing seeded global shift task: {$name}");
            self::assertNull($task->getDepartment(), "Seeded shift task {$name} should be global (no department)");
        }

        $types = $this->em->getRepository(VolunteerType::class);
        $volunteer = $types->findOneBy(['name' => 'Volunteer']);
        $staff = $types->findOneBy(['name' => 'Staff']);
        self::assertNotNull($volunteer);
        self::assertNotNull($staff);

        self::assertFalse($volunteer->isStaffOnly());
        self::assertTrue($volunteer->isShowOnDashboard());
        self::assertFalse($volunteer->isHideOnShiftView());

        self::assertTrue($staff->isStaffOnly());
        self::assertFalse($staff->isShowOnDashboard());
        self::assertTrue($staff->isHideOnShiftView());

        $general = $this->em->getRepository(Department::class)->findOneBy(['slug' => 'general']);
        self::assertNotNull($general);
        self::assertFalse($general->isOrganizational());
    }

    public function testSeedingIsIdempotent(): void
    {
        $this->installer()->seedDomainDefaults();
        $this->installer()->seedDomainDefaults();
        $this->em->clear();

        self::assertCount(7, $this->em->getRepository(ShiftTask::class)->findAll());
        self::assertCount(2, $this->em->getRepository(VolunteerType::class)->findAll());
    }
}
