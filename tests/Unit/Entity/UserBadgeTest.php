<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Badge;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserBadgeTest extends TestCase
{
    private function badge(string $slug, string $type, int $priority = 0): Badge
    {
        return (new Badge(ucfirst($slug), $slug, $type))->setPriority($priority);
    }

    public function testHighestPriorityPositionBadgeWins(): void
    {
        $user = new User();
        $user->addBadge($this->badge('staff', Badge::TYPE_POSITION, 20));
        $user->addBadge($this->badge('security', Badge::TYPE_STANDARD));
        $user->addBadge($this->badge('bod', Badge::TYPE_POSITION, 40));

        self::assertSame('bod', $user->getPositionBadge()?->getSlug());
    }

    public function testNoPositionBadgeYieldsNull(): void
    {
        $user = new User();
        $user->addBadge($this->badge('security', Badge::TYPE_STANDARD));

        self::assertNull($user->getPositionBadge());
    }

    public function testAddingSameBadgeTwiceKeepsOne(): void
    {
        $user = new User();
        $badge = $this->badge('staff', Badge::TYPE_POSITION, 20);
        $user->addBadge($badge);
        $user->addBadge($badge);

        self::assertCount(1, $user->getBadges());
        self::assertTrue($user->hasBadge($badge));
    }
}
