<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Service\Chat\ConversationService;
use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;

/**
 * Conversation initiation: only Admin/Sub Admin/Info Desk may start
 * a direct conversation; any user has a shared Info Desk support conversation
 * with the configured welcome message.
 */
final class ConversationServiceTest extends DatabaseTestCase
{
    private function service(): ConversationService
    {
        return static::getContainer()->get(ConversationService::class);
    }

    /** Info-desk membership is recognised by the exact `info-desk` slug, so that group is built verbatim. */
    private function user(string $name, ?string $role = null, ?string $groupSlug = null): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        if ($role !== null || $groupSlug !== null) {
            $group = new Group('G '.$name, ($groupSlug ?? 'g-'.$name).'-'.bin2hex(random_bytes(2)), $role);
            if ($groupSlug !== null) {
                $group = new Group('Info Desk', 'info-desk', 'ROLE_STAFF');
            }
            $this->em->persist($group);
            $u->addGroup($group);
        }
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    public function testPlainUserCannotStartDirectConversation(): void
    {
        $plain = $this->user('plain');
        self::assertFalse($this->service()->canInitiateDirect($plain));

        $this->expectException(\RuntimeException::class);
        $this->service()->startDirect($plain, $this->user('target'));
    }

    public function testInfoDeskCanStartDirectConversation(): void
    {
        $infodesk = $this->user('desk', null, 'info-desk');
        $target = $this->user('target');

        self::assertTrue($this->service()->canInitiateDirect($infodesk));
        $conversation = $this->service()->startDirect($infodesk, $target);
        self::assertSame(ConversationType::DIRECT, $conversation->getType());
        self::assertCount(2, $conversation->getParticipants());
    }

    /** Contacting support again reuses the open conversation instead of opening a second one. */
    public function testStartSupportShowsWelcomeAndIsShared(): void
    {
        static::getContainer()->get(EventConfigStore::class)->set(EventConfigStore::KEY_INFODESK_WELCOME, 'Welcome! How can we help?');
        $user = $this->user('vol');

        $first = $this->service()->startSupport($user);
        self::assertSame(ConversationType::SUPPORT, $first->getType());
        self::assertSame($user->getId(), $first->getSubject()->getId());
        self::assertCount(1, $first->getMessages(), 'welcome message present');
        self::assertSame('Welcome! How can we help?', $first->getMessages()->first()->getBody());

        $second = $this->service()->startSupport($user);
        self::assertSame($first->getId(), $second->getId());
    }

    public function testInternalNoticesHiddenFromSubject(): void
    {
        $user = $this->user('vol');
        $conversation = $this->service()->startSupport($user);
        $this->service()->post($conversation, null, 'Admin joined', internal: true);
        $this->service()->post($conversation, $user, 'I need help');

        $visibleToUser = $this->service()->visibleMessages($conversation, $user);
        foreach ($visibleToUser as $m) {
            self::assertFalse($m->isInternal(), 'the subject never sees internal notices');
        }
    }
}
