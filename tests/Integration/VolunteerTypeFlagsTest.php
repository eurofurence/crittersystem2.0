<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\VolunteerType;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class VolunteerTypeFlagsTest extends DatabaseTestCase
{
    private function validator(): ValidatorInterface
    {
        return static::getContainer()->get(ValidatorInterface::class);
    }

    private function type(bool $staffOnly, bool $showOnDashboard, bool $hideOnShiftView, bool $departmentOnly = false): VolunteerType
    {
        return (new VolunteerType('T'))
            ->setStaffOnly($staffOnly)
            ->setShowOnDashboard($showOnDashboard)
            ->setHideOnShiftView($hideOnShiftView)
            ->setDepartmentOnly($departmentOnly);
    }

    private function flagViolations(VolunteerType $type): int
    {
        $count = 0;
        foreach ($this->validator()->validate($type) as $violation) {
            if (\in_array($violation->getPropertyPath(), ['staffOnly', 'departmentOnly', 'hideOnShiftView', 'showOnDashboard', 'global'], true)) {
                ++$count;
            }
        }

        return $count;
    }

    public function testValidNonStaffType(): void
    {
        self::assertSame(0, $this->flagViolations($this->type(false, true, false)));
    }

    public function testValidStaffType(): void
    {
        self::assertSame(0, $this->flagViolations($this->type(true, false, true)));
    }

    public function testNonStaffMustShowOnDashboard(): void
    {
        self::assertGreaterThan(0, $this->flagViolations($this->type(false, false, false)));
    }

    public function testStaffMustHideOnShiftView(): void
    {
        self::assertGreaterThan(0, $this->flagViolations($this->type(true, false, false)));
    }

    public function testDepartmentOnlyRequiresStaffOnly(): void
    {
        self::assertGreaterThan(0, $this->flagViolations($this->type(false, true, false, true)));
    }

    /** A global type is the whole event's to use, so no department may restrict it to itself. */
    public function testGlobalTypeCannotBeDepartmentOnly(): void
    {
        $type = $this->type(true, false, true, true)->setGlobal(true);

        self::assertGreaterThan(0, $this->flagViolations($type));
    }

    public function testGlobalTypeIsOtherwiseValid(): void
    {
        self::assertSame(0, $this->flagViolations($this->type(true, false, true)->setGlobal(true)));
    }

    /**
     * Making a claimed type global is refused rather than silently dropping the claim, so the admin
     * decides what happens to the departments already relying on it.
     */
    public function testATypeADepartmentHasClaimedCannotBecomeGlobal(): void
    {
        $department = new Department('Stage', 'stage-'.bin2hex(random_bytes(2)));
        $type = $this->type(true, false, true);
        $this->em->persist($department);
        $this->em->persist($type);
        $department->addVolunteerType($type);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(VolunteerType::class)->find($type->getId());
        $reloaded->setGlobal(true);

        self::assertGreaterThan(0, $this->flagViolations($reloaded));
    }
}
