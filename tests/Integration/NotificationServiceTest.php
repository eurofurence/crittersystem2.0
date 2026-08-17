<?php

namespace App\Tests\Integration;

use App\Entity\NotificationPreference;
use App\Entity\Settings;
use App\Entity\User;
use App\Notification\NotificationCategories;
use App\Service\Notification\NotificationService;
use App\Tests\DatabaseTestCase;

final class NotificationServiceTest extends DatabaseTestCase
{
    private function service(): NotificationService
    {
        return static::getContainer()->get(NotificationService::class);
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->setSettings(new Settings($user));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testNotifyCreatesInAppAndCountsUnread(): void
    {
        $user = $this->makeUser('nora');

        $this->service()->notify($user, NotificationCategories::GENERAL, 'Hello', 'Body', '/somewhere');

        self::assertSame(1, $this->service()->unreadCount($user));
        $recent = $this->service()->recent($user);
        self::assertCount(1, $recent);
        self::assertSame('/somewhere', $recent[0]->getActionUrl());
    }

    public function testMarkAllRead(): void
    {
        $user = $this->makeUser('mika');
        $this->service()->notify($user, NotificationCategories::GENERAL, 'A', 'a');
        $this->service()->notify($user, NotificationCategories::GENERAL, 'B', 'b');
        self::assertSame(2, $this->service()->unreadCount($user));

        $this->service()->markAllRead($user);
        self::assertSame(0, $this->service()->unreadCount($user));
    }

    public function testInAppCanBeDisabledForNonSystemCategory(): void
    {
        $user = $this->makeUser('dana');
        $preference = (new NotificationPreference($user, NotificationCategories::SHIFT_REMINDER))->setInApp(false);
        $this->em->persist($preference);
        $this->em->flush();

        $created = $this->service()->notify($user, NotificationCategories::SHIFT_REMINDER, 'Reminder', 'soon');

        self::assertNull($created);
        self::assertSame(0, $this->service()->unreadCount($user));
    }

    /** A system category is mandatory in-app: the user's preference cannot switch it off. */
    public function testSystemCategoryAlwaysDeliversInApp(): void
    {
        $user = $this->makeUser('sysu');
        $preference = (new NotificationPreference($user, NotificationCategories::SECURITY))->setInApp(false);
        $this->em->persist($preference);
        $this->em->flush();

        $created = $this->service()->notify($user, NotificationCategories::SECURITY, '2FA reset', 'done');

        self::assertNotNull($created);
        self::assertTrue($created->isMandatory());
    }

    public function testPreferenceMatrixLocksInfoDeskToInAppOnly(): void
    {
        $user = $this->makeUser('idk');
        $this->service()->savePreferences($user, [
            NotificationCategories::INFO_DESK => ['inApp' => true, 'email' => true, 'telegram' => true],
        ]);

        $row = null;
        foreach ($this->service()->preferenceMatrix($user) as $r) {
            if ($r['category'] === NotificationCategories::INFO_DESK) {
                $row = $r;
            }
        }

        self::assertNotNull($row);
        self::assertTrue($row['inAppOnly']);
        self::assertFalse($row['email']);
        self::assertFalse($row['telegram']);
    }

    /** Without a user choice the lead is the system default of 1800 seconds, reported as 30 minutes. */
    public function testReminderLeadFallsBackToSystemDefault(): void
    {
        $user = $this->makeUser('remy');

        self::assertSame(30, $this->service()->reminderLeadMinutes($user));

        $user->getSettings()->setNotificationReminderLead(15);
        $this->em->flush();
        self::assertSame(15, $this->service()->reminderLeadMinutes($user));
    }
}
