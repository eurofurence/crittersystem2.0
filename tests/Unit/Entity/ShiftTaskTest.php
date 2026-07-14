<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Department;
use App\Entity\ShiftTask;
use PHPUnit\Framework\TestCase;

final class ShiftTaskTest extends TestCase
{
    public function testGlobalTaskDisplaysItsName(): void
    {
        $task = new ShiftTask('Setup');

        self::assertTrue($task->isGlobal());
        self::assertSame('Setup', $task->displayName());
    }

    public function testDepartmentTaskDisplaysWithDepartmentPrefix(): void
    {
        $department = new Department('Stage', 'stage');
        $task = (new ShiftTask('Rigging'))->setDepartment($department);

        self::assertFalse($task->isGlobal());
        self::assertSame('Stage: Rigging', $task->displayName());
    }
}
