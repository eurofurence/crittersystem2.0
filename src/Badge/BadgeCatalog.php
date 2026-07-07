<?php

declare(strict_types=1);

namespace App\Badge;

use App\Entity\Badge;

/**
 * The badges seeded on first install: the four ranked position badges
 * (BoD > Director > Staff > Volunteer) and a few standard identification badges.
 * Admins can add more via the badge admin.
 */
final class BadgeCatalog
{
    /**
     * slug => [name, type, priority, color].
     *
     * @var array<string, array{name: string, type: string, priority: int, color: string}>
     */
    public const BADGES = [
        'bod' => ['name' => 'BoD', 'type' => Badge::TYPE_POSITION, 'priority' => 40, 'color' => 'purple'],
        'director' => ['name' => 'Director', 'type' => Badge::TYPE_POSITION, 'priority' => 30, 'color' => 'red'],
        'staff' => ['name' => 'Staff', 'type' => Badge::TYPE_POSITION, 'priority' => 20, 'color' => 'azure'],
        'volunteer' => ['name' => 'Volunteer', 'type' => Badge::TYPE_POSITION, 'priority' => 10, 'color' => 'green'],

        'medical-support' => ['name' => 'Medical Support', 'type' => Badge::TYPE_STANDARD, 'priority' => 0, 'color' => 'red'],
        'security' => ['name' => 'Security', 'type' => Badge::TYPE_STANDARD, 'priority' => 0, 'color' => 'dark'],
        'logistics' => ['name' => 'Logistics', 'type' => Badge::TYPE_STANDARD, 'priority' => 0, 'color' => 'orange'],
    ];
}
