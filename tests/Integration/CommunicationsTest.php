<?php

namespace App\Tests\Integration;

use App\Entity\Message;
use App\Entity\News;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\NewsRepository;
use App\Tests\DatabaseTestCase;

final class CommunicationsTest extends DatabaseTestCase
{
    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    public function testNewsFeedHidesStaffOnlyUnlessRequested(): void
    {
        $public = (new News())->setTitle('Public')->setText('hi');
        $staff = (new News())->setTitle('Staff')->setText('secret')->setStaffOnly(true);
        $pinned = (new News())->setTitle('Pinned')->setText('top')->setIsPinned(true);
        $this->em->persist($public);
        $this->em->persist($staff);
        $this->em->persist($pinned);
        $this->em->flush();

        /** @var NewsRepository $repo */
        $repo = $this->em->getRepository(News::class);

        $public = $repo->findFeed(false);
        self::assertCount(2, $public); // staff-only excluded
        self::assertSame('Pinned', $public[0]->getTitle()); // pinned first

        self::assertCount(3, $repo->findFeed(true));
    }

    public function testConversationUnreadAndMarkRead(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->em->persist(new Message($alice, $bob, 'hello'));
        $this->em->persist(new Message($bob, $alice, 'hi back'));
        $this->em->persist(new Message($alice, $bob, 'you free?'));
        $this->em->flush();

        /** @var MessageRepository $repo */
        $repo = $this->em->getRepository(Message::class);

        self::assertCount(3, $repo->findConversation($alice, $bob));
        self::assertSame(2, $repo->countUnread($bob)); // two from alice
        self::assertSame(1, $repo->countUnread($alice)); // one from bob

        $repo->markConversationRead($bob, $alice);
        $this->em->clear();

        self::assertSame(0, $this->em->getRepository(Message::class)->countUnread($bob));
    }
}
