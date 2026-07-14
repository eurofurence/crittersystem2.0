<?php

namespace App\Tests\Feature;

use App\Entity\Settings;
use App\Entity\User;
use App\Notification\NotificationCategories;
use App\Service\Notification\NotificationService;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationControllerTest extends DatabaseWebTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setName('notifuser')->setEmail('notif@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $this->user->setPassword($hasher->hashPassword($this->user, 'secret123'));
        $this->user->setSettings(new Settings($this->user));
        $this->user->completeOnboarding();
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    private function service(): NotificationService
    {
        return static::getContainer()->get(NotificationService::class);
    }

    public function testBellShowsUnreadCount(): void
    {
        $this->service()->notify($this->user, NotificationCategories::GENERAL, 'Ping', 'hi');

        $crawler = $this->client->request('GET', '/notifications/bell');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.badge', '1');
    }

    public function testHistoryPageLists(): void
    {
        $this->service()->notify($this->user, NotificationCategories::GENERAL, 'Ping', 'hi');

        $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ping');
    }

    public function testOpenMarksReadAndRedirects(): void
    {
        $n = $this->service()->notify($this->user, NotificationCategories::GENERAL, 'Go', 'there', '/dashboard');

        $this->client->request('GET', '/notifications/open/'.$n->getUuid());
        self::assertResponseRedirects('/dashboard');
        self::assertSame(0, $this->service()->unreadCount($this->user));
    }

    public function testPreferencesSave(): void
    {
        $crawler = $this->client->request('GET', '/notifications/preferences');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save preferences')->form();
        $form['pref['.NotificationCategories::SHIFT_REMINDER.'][email]'] = '1';
        $form['reminder_lead'] = '15';
        $this->client->submit($form);

        self::assertResponseRedirects('/notifications/preferences');

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($this->user->getId());
        self::assertSame(15, $reloaded->getSettings()?->getNotificationReminderLead());
    }
}
