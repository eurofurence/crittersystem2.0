<?php

namespace App\Tests\Integration;

use App\Entity\Location;
use App\Repository\LocationRepository;
use App\Tests\DatabaseTestCase;

/**
 * Locations are shown by their ancestor path rather than their bare name: most of the tree is
 * children called "Hall 5" or "R 030", which say nothing about which building they are in.
 *
 * This pins the ordering the pickers depend on - a child immediately under its own parent, and
 * numbers read as numbers - and the composed label itself.
 */
final class LocationPathOrderingTest extends DatabaseTestCase
{
    private function locations(): LocationRepository
    {
        return static::getContainer()->get(LocationRepository::class);
    }

    private function make(string $name, ?Location $parent = null): Location
    {
        $location = (new Location($name))->setAlias(strtolower(str_replace(' ', '-', $name)));
        if ($parent !== null) {
            $location->setParent($parent);
        }
        $this->em->persist($location);

        return $location;
    }

    public function testChildrenFollowTheirOwnParent(): void
    {
        $first = $this->make('CCH First Floor');
        $ground = $this->make('CCH Ground Floor');
        $this->make('Hall 5', $first);
        $this->make('Check-in 1', $ground);
        $this->make('Radisson');
        $this->em->flush();

        $names = array_map(static fn (Location $l): string => $l->fullName(), $this->locations()->findAllOrderedByPath());

        self::assertSame([
            'CCH First Floor',
            'CCH First Floor - Hall 5',
            'CCH Ground Floor',
            'CCH Ground Floor - Check-in 1',
            'Radisson',
        ], $names);
    }

    /**
     * Plain string ordering puts "Hall 10" before "Hall 3", which is exactly the confusion this
     * ordering exists to remove.
     */
    public function testNumberedSiblingsSortNumerically(): void
    {
        $floor = $this->make('CCH First Floor');
        foreach (['Hall 10', 'Hall 3', 'Hall 9'] as $name) {
            $this->make($name, $floor);
        }
        $this->em->flush();

        $names = array_map(static fn (Location $l): string => $l->fullName(), $this->locations()->findAllOrderedByPath());

        self::assertSame([
            'CCH First Floor',
            'CCH First Floor - Hall 3',
            'CCH First Floor - Hall 9',
            'CCH First Floor - Hall 10',
        ], $names);
    }

    public function testTheFullPathReachesThreeLevels(): void
    {
        $root = $this->make('Main Hall');
        $child = $this->make('Check-in Counter', $root);
        $grandchild = $this->make('Desk 2', $child);
        $this->em->flush();

        self::assertSame('Main Hall - Check-in Counter - Desk 2', $grandchild->fullName());
    }

    /**
     * A root whose name extends another root's must not slip between that root and its children.
     */
    public function testASiblingRootDoesNotSplitAParentFromItsChildren(): void
    {
        $floor = $this->make('CCH First Floor');
        $this->make('Hall 5', $floor);
        $this->make('CCH First Floor Annex');
        $this->em->flush();

        $names = array_map(static fn (Location $l): string => $l->fullName(), $this->locations()->findAllOrderedByPath());

        self::assertSame([
            'CCH First Floor',
            'CCH First Floor - Hall 5',
            'CCH First Floor Annex',
        ], $names);
    }
}
