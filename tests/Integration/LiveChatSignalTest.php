<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Mercure\TopicBuilder;
use App\Mercure\Topics;
use App\Mercure\UpdatePublisher;
use App\Service\Chat\ConversationService;
use App\Service\Chat\InfoDeskQueueService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\RecordedUpdates;

/**
 * Who may receive a conversation's live updates, and what those updates contain.
 *
 * A support thread is the sharpest case in the application: its participants and every Info Desk
 * responder read the same conversation, but they do not read the same thread - internal notices are
 * hidden from the subject. Pushing the thread itself would therefore be a leak that renders
 * perfectly in every test that only checks the markup. Only a signal is published, and each reader
 * re-requests the thread so the server decides what they see.
 */
final class LiveChatSignalTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordedUpdates::clear();
    }

    private function chat(): ConversationService
    {
        return static::getContainer()->get(ConversationService::class);
    }

    private function queue(): InfoDeskQueueService
    {
        return static::getContainer()->get(InfoDeskQueueService::class);
    }

    private function topics(): TopicBuilder
    {
        return static::getContainer()->get(TopicBuilder::class);
    }

    private function flush(): void
    {
        static::getContainer()->get(UpdatePublisher::class)->flush();
    }

    private function user(string $name, string ...$privileges): User
    {
        $group = new Group(ucfirst($name), $name.'-'.bin2hex(random_bytes(2)), $privileges === [] ? null : 'ROLE_STAFF');
        foreach ($privileges as $privilege) {
            $entity = new Privilege($privilege);
            $this->em->persist($entity);
            $group->addPrivilege($entity);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $this->em->persist($user);

        return $user;
    }

    /** A message wakes the thread, and says nothing about itself. */
    public function testPostingSignalsTheConversationWithoutItsContent(): void
    {
        $subject = $this->user('subject');
        $this->em->flush();

        $conversation = $this->chat()->startSupport($subject);
        $this->flush();
        RecordedUpdates::clear();

        $this->chat()->post($conversation, $subject, 'My badge number is 4711 and I lost my key.');
        $this->flush();

        $updates = RecordedUpdates::forTopic(Topics::conversation($conversation));
        self::assertCount(1, $updates);
        self::assertStringNotContainsString('4711', $updates[0]->getData());
        self::assertStringNotContainsString('lost my key', $updates[0]->getData());
        self::assertTrue($updates[0]->isPrivate());
    }

    /**
     * An internal notice must not even be distinguishable on the wire.
     *
     * It is hidden from the support subject when the thread is rendered, and the subject holds this
     * conversation's topic. If the signal said "an internal note was added" they would learn of
     * something they may not read; if it carried the note they would have it outright.
     */
    public function testAnInternalNoticeSignalsExactlyLikeAnyOtherMessage(): void
    {
        $subject = $this->user('subject');
        $responder = $this->user('responder', 'chat:claim');
        $this->em->flush();

        $conversation = $this->chat()->startSupport($subject);
        $this->flush();

        RecordedUpdates::clear();
        $this->chat()->post($conversation, $responder, 'Suspected repeat offender, check the ledger.', internal: true);
        $this->flush();
        $internal = RecordedUpdates::forTopic(Topics::conversation($conversation))[0]->getData();

        RecordedUpdates::clear();
        $this->chat()->post($conversation, $responder, 'Hello, how can we help?');
        $this->flush();
        $ordinary = RecordedUpdates::forTopic(Topics::conversation($conversation))[0]->getData();

        self::assertSame($ordinary, $internal, 'an internal notice must be indistinguishable on the wire');
        self::assertStringNotContainsString('ledger', $internal);
    }

    /** The subject holds their own thread's topic; an unrelated volunteer does not. */
    public function testOnlyReadersOfAConversationHoldItsTopic(): void
    {
        $subject = $this->user('subject');
        $stranger = $this->user('stranger');
        $this->em->flush();

        $conversation = $this->chat()->startSupport($subject);
        $this->em->flush();

        self::assertContains(Topics::conversation($conversation), $this->topics()->forUser($subject));
        self::assertNotContains(
            Topics::conversation($conversation),
            $this->topics()->forUser($stranger),
            'a stranger must not be able to subscribe to someone else\'s support thread',
        );
    }

    /**
     * Holding a conversation's topic implies permission to read it.
     *
     * The direction that matters: the token may be narrower than the predicate without harm, but
     * never wider, because a wider token means live updates reaching someone a request would refuse.
     * The controller and the topic builder ask ConversationService the same question so the two
     * cannot drift; this pins the property they exist to guarantee.
     */
    public function testHoldingAConversationTopicImpliesPermissionToReadIt(): void
    {
        $subject = $this->user('subject');
        $responder = $this->user('responder', 'chat:claim');
        $stranger = $this->user('stranger');
        $this->em->flush();

        $conversation = $this->chat()->startSupport($subject);
        $this->em->flush();

        foreach ([$subject, $responder, $stranger] as $user) {
            if (\in_array(Topics::conversation($conversation), $this->topics()->forUser($user), true)) {
                self::assertTrue(
                    $this->chat()->mayParticipate($conversation, $user),
                    $user->getName().' holds the topic but would be refused the conversation',
                );
            }
        }

        // And the one who may not read it holds neither route to it.
        $strangerTopics = $this->topics()->forUser($stranger);
        self::assertNotContains(Topics::conversation($conversation), $strangerTopics);
        self::assertNotContains(Topics::infoDeskQueue(), $strangerTopics);
    }

    /** Claiming reaches both the thread and the queue that other responders are watching. */
    public function testClaimingSignalsTheThreadAndTheQueue(): void
    {
        $subject = $this->user('subject');
        $responder = $this->user('responder', 'chat:claim');
        $this->em->flush();

        $conversation = $this->chat()->startSupport($subject);
        $this->flush();
        RecordedUpdates::clear();

        $this->queue()->claim($conversation, $responder, false);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::conversation($conversation)));
        self::assertCount(1, RecordedUpdates::forTopic(Topics::infoDeskQueue()));
    }

    /** A direct conversation is nobody's queue business. */
    public function testADirectConversationNeverReachesTheInfoDeskQueue(): void
    {
        // Only an Admin, Sub Admin or Info Desk member may open a direct conversation.
        $group = new Group('Sub admin', 'subadmin-'.bin2hex(random_bytes(2)), 'ROLE_SUBADMIN');
        $this->em->persist($group);
        $responder = new User();
        $responder->setName('initiator')->setEmail('initiator@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $responder->addGroup($group);
        $this->em->persist($responder);

        $other = $this->user('other');
        $this->em->flush();

        $conversation = $this->chat()->startDirect($responder, $other);
        $this->flush();
        RecordedUpdates::clear();

        $this->chat()->post($conversation, $responder, 'a private word');
        $this->flush();

        self::assertCount(0, RecordedUpdates::forTopic(Topics::infoDeskQueue()));
        self::assertCount(1, RecordedUpdates::forTopic(Topics::conversation($conversation)));
    }

    /** Typing has to reach the other side while it is still true. */
    public function testTypingSignalsTheConversation(): void
    {
        $subject = $this->user('subject');
        $this->em->flush();

        $conversation = $this->chat()->startSupport($subject);
        $this->flush();
        RecordedUpdates::clear();

        $this->chat()->markTyping($conversation, $subject);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::conversation($conversation)));
    }
}
