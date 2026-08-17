<?php

namespace App\Tests\Unit\Service;

use App\Entity\Group;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Notifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class NotifierTest extends TestCase
{
    /** @param string[] $privileges */
    private function user(string $name, array $privileges = [], ?string $role = null): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com');
        if ($privileges !== [] || $role !== null) {
            $group = new Group('G'.$name, 'g'.$name, $role);
            foreach ($privileges as $p) {
                $group->addPrivilege(new Privilege($p));
            }
            $user->addGroup($group);
        }

        return $user;
    }

    public function testStaffOnlyNewsEmailsOnlyStaffSubscribers(): void
    {
        $staff = $this->user('staff', [], 'ROLE_STAFF');
        $plain = $this->user('plain');

        $users = $this->createStub(UserRepository::class);
        $users->method('findSubscribedToNews')->willReturn([$staff, $plain]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $news = (new News())->setTitle('Ops briefing')->setText('secret')->setStaffOnly(true);

        self::assertSame(1, (new Notifier($mailer, $users))->newsPublished($news));
    }

    public function testPublicNewsEmailsAllSubscribers(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findSubscribedToNews')->willReturn([$this->user('a'), $this->user('b')]);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))->method('send');

        $news = (new News())->setTitle('Welcome')->setText('hi');

        self::assertSame(2, (new Notifier($mailer, $users))->newsPublished($news));
    }

    /** Message email is opt-in: a freshly created Settings row leaves emailMessages false. */
    public function testMessageEmailRespectsPreference(): void
    {
        $sender = $this->user('sender');

        $optedIn = $this->user('in');
        $settingsIn = new Settings($optedIn);
        $settingsIn->setEmailMessages(true);
        $optedIn->setSettings($settingsIn);

        $optedOut = $this->user('out');
        $optedOut->setSettings(new Settings($optedOut));

        $users = $this->createStub(UserRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $notifier = new Notifier($mailer, $users);
        self::assertTrue($notifier->messageSent(new Message($sender, $optedIn, 'hi')));
        self::assertFalse($notifier->messageSent(new Message($sender, $optedOut, 'hi')));
    }
}
