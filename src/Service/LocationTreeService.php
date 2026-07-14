<?php

namespace App\Service;

use App\Entity\Location;
use App\Entity\User;
use App\Repository\LocationRepository;

/**
 * Location hierarchy and visibility. Staff-only nodes (and
 * their descendants) are hidden from non-staff viewers, and a hidden parent
 * never leaks its children.
 */
class LocationTreeService
{
    public function __construct(private readonly LocationRepository $locations)
    {
    }

    public function isVisible(Location $location, User $viewer): bool
    {
        return $viewer->isStaff() || !$location->effectiveStaffOnly();
    }

    /**
     * Visible root locations, each with its visible children (recursively).
     *
     * @return array<int, array{location: Location, children: array}>
     */
    public function visibleTree(User $viewer): array
    {
        $tree = [];
        foreach ($this->locations->findRootsOrdered() as $root) {
            if ($this->isVisible($root, $viewer)) {
                $tree[] = $this->node($root, $viewer);
            }
        }

        return $tree;
    }

    /**
     * @return array{location: Location, children: array}
     */
    private function node(Location $location, User $viewer): array
    {
        $children = [];
        foreach ($location->getChildren() as $child) {
            if ($this->isVisible($child, $viewer)) {
                $children[] = $this->node($child, $viewer);
            }
        }
        // Stable ordering by name.
        usort($children, static fn ($a, $b) => strcmp($a['location']->getName(), $b['location']->getName()));

        return ['location' => $location, 'children' => $children];
    }
}
