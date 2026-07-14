<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Location;
use PHPUnit\Framework\TestCase;

final class LocationHierarchyTest extends TestCase
{
    public function testFullNameAndDepth(): void
    {
        $root = new Location('Main Hall');
        $child = (new Location('Check-in Counter'))->setParent($root);
        $grandchild = (new Location('Desk 2'))->setParent($child);

        self::assertSame(0, $root->depth());
        self::assertSame(2, $grandchild->depth());
        self::assertSame('Main Hall - Check-in Counter - Desk 2', $grandchild->fullName());
    }

    public function testEffectiveStaffOnlyInheritsFromAncestor(): void
    {
        $root = (new Location('Backstage'))->setStaffOnly(true);
        $child = (new Location('Green Room'))->setParent($root);

        self::assertTrue($child->effectiveStaffOnly());
        self::assertFalse($child->isStaffOnly());
    }
}
