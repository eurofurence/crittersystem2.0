<?php

namespace App\Tests\Unit\Twig;

use App\Entity\User;
use App\Service\Notification\NotificationService;
use App\Service\OperationalStatusService;
use App\Twig\NotificationExtension;
use App\Twig\OperationalStatusExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The navbar widgets render on every page through the shared layout, error pages
 * included. A failing widget must degrade to hidden (null) so it cannot turn the
 * friendly error page back into a 500.
 */
final class NavWidgetResilienceTest extends TestCase
{
    public function testNotificationBellReturnsNullWhenServiceThrows(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('unreadCount')->willThrowException(new \RuntimeException('db down'));

        $ext = new NotificationExtension($notifications, $security, new NullLogger());

        self::assertNull($ext->bell());
    }

    public function testOperationalStatusReturnsNullWhenServiceThrows(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());

        $status = $this->createStub(OperationalStatusService::class);
        $status->method('viewModel')->willThrowException(new \RuntimeException('db down'));

        $ext = new OperationalStatusExtension($status, $security, new NullLogger());

        self::assertNull($ext->currentStatus());
    }
}
