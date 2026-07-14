<?php

namespace App\Tests\Integration;

use App\Entity\ChatMessage;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Service\Chat\ConversationService;
use App\Tests\DatabaseTestCase;

/**
 * Message editing window and restricted content.
 */
final class ChatEditRestrictTest extends DatabaseTestCase
{
    private function chat(): ConversationService
    {
        return static::getContainer()->get(ConversationService::class);
    }

    private function user(string $name): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function message(User $sender, string $body, string $age = 'now'): ChatMessage
    {
        $c = new Conversation(ConversationType::DIRECT);
        $this->em->persist($c);
        $m = new ChatMessage($c, $sender, $body);
        $this->em->persist($m);
        $this->em->flush();
        if ($age !== 'now') {
            (new \ReflectionProperty(ChatMessage::class, 'createdAt'))->setValue($m, new \DateTimeImmutable($age));
            $this->em->flush();
        }

        return $m;
    }

    public function testLinksRejectedForRegularUsersAllowedForPrivileged(): void
    {
        self::assertNotNull($this->chat()->restrictedContentError('see https://evil.example', false));
        self::assertNull($this->chat()->restrictedContentError('see https://ok.example', true));
        self::assertNull($this->chat()->restrictedContentError('no links here', false));
    }

    public function testEditWithinWindowSucceeds(): void
    {
        $user = $this->user('sam');
        $message = $this->message($user, 'orignal');

        $this->chat()->editMessage($message, $user, 'original', true);
        self::assertSame('original', $message->getBody());
        self::assertNotNull($message->getEditedAt());
    }

    public function testEditAfterWindowIsRejected(): void
    {
        $user = $this->user('sam');
        $message = $this->message($user, 'old', '-10 minutes');

        $this->expectException(\RuntimeException::class);
        $this->chat()->editMessage($message, $user, 'too late', true);
    }

    public function testOnlySenderMayEdit(): void
    {
        $author = $this->user('author');
        $other = $this->user('other');
        $message = $this->message($author, 'hi');

        self::assertNotNull($this->chat()->editMessageError($message, $other));
    }
}
