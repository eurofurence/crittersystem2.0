<?php

namespace App\Tests\Integration;

use App\Entity\ChatMessage;
use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Purging the conversations a defect created.
 *
 * This deletes rows a volunteer's name is attached to, so what it will NOT delete matters more than
 * what it will: never a conversation anyone has written in, never a direct conversation, and never
 * one so recent that its author may still be typing - opening a conversation before writing in it is
 * a legitimate first step, and the fix for the defect did not change that.
 */
final class PurgeEmptySupportConversationsTest extends DatabaseTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find('app:chat:purge-empty-support'));
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    /** createdAt is set on construction, so the row is aged afterwards in SQL. */
    private function conversation(User $subject, ConversationType $type, string $createdAgo): Conversation
    {
        $conversation = new Conversation($type, $type === ConversationType::SUPPORT ? $subject : null);
        $this->em->persist($conversation);
        $this->em->persist(new ConversationParticipant($conversation, $subject));
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE conversations SET created_at = :at WHERE id = :id',
            ['at' => (new \DateTimeImmutable($createdAgo))->format('Y-m-d H:i:s'), 'id' => $conversation->getId()],
        );
        $this->em->refresh($conversation);

        return $conversation;
    }

    private function conversationCount(): int
    {
        return (int) $this->em->getRepository(Conversation::class)->count([]);
    }

    /** Reporting is the default: deleting requires saying so. */
    public function testWithoutForceItOnlyReports(): void
    {
        $this->conversation($this->user('idle'), ConversationType::SUPPORT, '-3 days');

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(1, $this->conversationCount(), 'nothing may be deleted without --force');
        self::assertStringContainsString('would be removed', $tester->getDisplay());
    }

    public function testWithForceItRemovesTheEmptyOnes(): void
    {
        $this->conversation($this->user('idle'), ConversationType::SUPPORT, '-3 days');

        $this->tester()->execute(['--force' => true]);

        self::assertSame(0, $this->conversationCount());
    }

    /** A conversation someone has written in is work, not residue. */
    public function testAConversationWithAMessageIsKept(): void
    {
        $user = $this->user('writer');
        $conversation = $this->conversation($user, ConversationType::SUPPORT, '-3 days');
        $this->em->persist(new ChatMessage($conversation, $user, 'I lost my badge.'));
        $this->em->flush();

        $this->tester()->execute(['--force' => true]);

        self::assertSame(1, $this->conversationCount(), 'a conversation with a message must never be removed');
    }

    /** Even a welcome message from the system counts: something is there to read. */
    public function testAConversationWithOnlyAWelcomeMessageIsKept(): void
    {
        $conversation = $this->conversation($this->user('greeted'), ConversationType::SUPPORT, '-3 days');
        $this->em->persist(new ChatMessage($conversation, null, 'Welcome to the Info Desk.'));
        $this->em->flush();

        $this->tester()->execute(['--force' => true]);

        self::assertSame(1, $this->conversationCount());
    }

    /** The author may still be typing. */
    public function testARecentlyOpenedConversationIsLeftAlone(): void
    {
        $this->conversation($this->user('typing'), ConversationType::SUPPORT, '-10 minutes');

        $this->tester()->execute(['--force' => true]);

        self::assertSame(1, $this->conversationCount(), 'a conversation opened minutes ago may be about to be used');
    }

    /** The age guard is adjustable, and honoured. */
    public function testTheAgeGuardIsHonoured(): void
    {
        $this->conversation($this->user('typing'), ConversationType::SUPPORT, '-10 minutes');

        $this->tester()->execute(['--force' => true, '--min-age-hours' => '0']);

        self::assertSame(0, $this->conversationCount());
    }

    /** Direct conversations are none of this command's business. */
    public function testADirectConversationIsNeverTouched(): void
    {
        $this->conversation($this->user('someone'), ConversationType::DIRECT, '-3 days');

        $this->tester()->execute(['--force' => true]);

        self::assertSame(1, $this->conversationCount(), 'only support conversations are in scope');
    }
}
