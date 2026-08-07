<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\Shift\SchedulePdfLayout;
use PHPUnit\Framework\TestCase;

/**
 * Choosing the page for a schedule export. Pure arithmetic, so no kernel and no database.
 */
final class SchedulePdfLayoutTest extends TestCase
{
    /** @return list<User> */
    private function users(int $count): array
    {
        $users = [];
        for ($i = 0; $i < $count; ++$i) {
            $users[] = (new User())->setName(\sprintf('user%02d', $i));
        }

        return $users;
    }

    private function layout(int $count): array
    {
        return (new SchedulePdfLayout())->forUsers($this->users($count));
    }

    /** A department of four does not need a landscape page, so it does not get one. */
    public function testASmallDepartmentGetsAPortraitPage(): void
    {
        $layout = $this->layout(4);

        self::assertSame('a4', $layout['paper']);
        self::assertSame('portrait', $layout['orientation']);
        self::assertCount(1, $layout['chunks']);
    }

    public function testAWiderDepartmentTurnsThePageSideways(): void
    {
        $layout = $this->layout(14);

        self::assertSame('a4', $layout['paper']);
        self::assertSame('landscape', $layout['orientation']);
        self::assertCount(1, $layout['chunks']);
    }

    public function testAnEvenWiderOneGoesUpAPaperSize(): void
    {
        $layout = $this->layout(20);

        self::assertSame('a3', $layout['paper']);
        self::assertSame('landscape', $layout['orientation']);
        self::assertCount(1, $layout['chunks'], 'still one page across');
    }

    /**
     * Past the point where a column would stop being legible, the people are split across pages
     * rather than squeezed. Nobody may be dropped in the process, and the order has to survive.
     */
    public function testALargeDepartmentIsSplitAcrossPagesWithNobodyLost(): void
    {
        $users = $this->users(60);
        $layout = (new SchedulePdfLayout())->forUsers($users);

        self::assertGreaterThan(1, \count($layout['chunks']));
        self::assertSame(
            array_map(static fn (User $u): string => $u->getName(), $users),
            array_map(static fn (User $u): string => $u->getName(), array_merge(...$layout['chunks'])),
        );
    }

    /** Columns are narrowed before anything is split, but never below what can be read. */
    public function testColumnsNarrowWithTheCountAndStopAtTheLegibleFloor(): void
    {
        self::assertGreaterThan($this->layout(14)['columnPt'], $this->layout(4)['columnPt']);
        self::assertGreaterThanOrEqual(40, $this->layout(60)['columnPt']);
        self::assertGreaterThanOrEqual(8, $this->layout(60)['fontPt']);
    }

    /** A department with nothing scheduled still has to produce a page. */
    public function testNobodyAssignedStillProducesOnePage(): void
    {
        $layout = (new SchedulePdfLayout())->forUsers([]);

        self::assertCount(1, $layout['chunks']);
        self::assertSame([], $layout['chunks'][0]);
    }
}
