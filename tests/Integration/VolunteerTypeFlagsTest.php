<?php

namespace App\Tests\Integration;

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
            if (\in_array($violation->getPropertyPath(), ['staffOnly', 'departmentOnly', 'hideOnShiftView', 'showOnDashboard'], true)) {
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
}
