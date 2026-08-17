<?php

namespace App\Tests\Integration;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationStatus;
use App\Enum\ConversationType;
use App\Service\Chat\ConversationService;
use App\Service\Chat\InfoDeskQueueService;
use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;

/**
 * Info Desk queue claiming: a claim locks out other claimers,
 * an idle claim is released after the timeout, exclusive claims are labelled
 * differently, and closing finalizes the conversation.
 */
final class InfoDeskQueueServiceTest extends DatabaseTestCase
{
    private function queue(): InfoDeskQueueService
    {
        return static::getContainer()->get(InfoDeskQueueService::class);
    }

    private function user(string $name): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function supportConversation(): Conversation
    {
        return static::getContainer()->get(ConversationService::class)->startSupport($this->user('subject'));
    }

    public function testClaimLocksOutOtherClaimers(): void
    {
        $conversation = $this->supportConversation();
        $desk1 = $this->user('desk1');
        $desk2 = $this->user('desk2');

        $this->queue()->claim($conversation, $desk1, false);
        self::assertSame($desk1->getId(), $conversation->getClaimedBy()->getId());

        $this->expectException(\RuntimeException::class);
        $this->queue()->claim($conversation, $desk2, false);
    }

    /** The claim time cannot be set through the service, so reflection ages it past the timeout. */
    public function testTimedOutClaimIsReleasedAndReclaimable(): void
    {
        $conversation = $this->supportConversation();
        $desk1 = $this->user('desk1');
        $desk2 = $this->user('desk2');

        $this->queue()->claim($conversation, $desk1, false);
        $ref = new \ReflectionProperty(Conversation::class, 'claimedAt');
        $ref->setValue($conversation, new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        self::assertTrue($this->queue()->releaseIfTimedOut($conversation));
        $reclaimed = $this->queue()->claim($conversation, $desk2, false);
        self::assertSame($desk2->getId(), $reclaimed->getClaimedBy()->getId());
    }

    /**
     * The stored timeout is seconds, which is the unit the operations screen asks for and saves.
     *
     * Reading it as minutes held an idle claim for five hours instead of five minutes, and only
     * once somebody had saved that screen: left unset, the fallback happened to mean five minutes
     * under either reading, so nothing here noticed.
     */
    public function testAStoredTimeoutIsReadAsSeconds(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_INFODESK_CLAIM_TIMEOUT, 300);
        $store->flush();

        $conversation = $this->supportConversation();
        $this->queue()->claim($conversation, $this->user('desk1'), false);

        $ref = new \ReflectionProperty(Conversation::class, 'claimedAt');
        $ref->setValue($conversation, new \DateTimeImmutable('-10 minutes'));
        $this->em->flush();

        self::assertTrue(
            $this->queue()->releaseIfTimedOut($conversation),
            'a claim idle for ten minutes is past a three hundred second timeout',
        );
    }

    /** A claim younger than the timeout is left alone. */
    public function testAClaimInsideTheTimeoutSurvives(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_INFODESK_CLAIM_TIMEOUT, 300);
        $store->flush();

        $conversation = $this->supportConversation();
        $this->queue()->claim($conversation, $this->user('desk1'), false);

        $ref = new \ReflectionProperty(Conversation::class, 'claimedAt');
        $ref->setValue($conversation, new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        self::assertFalse($this->queue()->releaseIfTimedOut($conversation));
    }

    public function testUnclaimReleasesOwnership(): void
    {
        $conversation = $this->supportConversation();
        $desk = $this->user('desk');
        $this->queue()->claim($conversation, $desk, false);
        $this->queue()->unclaim($conversation, $desk);

        self::assertFalse($conversation->isClaimed());
    }

    public function testExclusiveClaimIsLabelledAdministrator(): void
    {
        $conversation = $this->supportConversation();
        $admin = $this->user('boss');
        $this->queue()->claim($conversation, $admin, true);

        self::assertStringStartsWith('Administrator - ', $this->queue()->ownerLabel($conversation));
    }

    public function testCloseFinalizesConversation(): void
    {
        $conversation = $this->supportConversation();
        $desk = $this->user('desk');
        $this->queue()->claim($conversation, $desk, false);
        $this->queue()->close($conversation, $desk);

        self::assertSame(ConversationStatus::CLOSED, $conversation->getStatus());
        self::assertFalse($conversation->isClaimed());
    }

    public function testWaitingListExcludesClaimed(): void
    {
        $conversation = $this->supportConversation();
        self::assertCount(1, $this->queue()->waiting());

        $this->queue()->claim($conversation, $this->user('desk'), false);
        self::assertCount(0, $this->queue()->waiting());
    }
}
