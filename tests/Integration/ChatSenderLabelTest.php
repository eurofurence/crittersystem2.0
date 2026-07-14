<?php

namespace App\Tests\Integration;

use App\Entity\ChatMessage;
use App\Entity\Conversation;
use App\Entity\Group;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Service\Chat\ConversationService;
use App\Tests\DatabaseTestCase;

/** Sender display labels. */
final class ChatSenderLabelTest extends DatabaseTestCase
{
    private function chat(): ConversationService
    {
        return static::getContainer()->get(ConversationService::class);
    }

    private function user(string $name, ?string $role = null, bool $infoDesk = false): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        if ($role !== null) {
            $g = new Group('R '.$name, 'r-'.$name.'-'.bin2hex(random_bytes(2)), $role);
            $this->em->persist($g);
            $u->addGroup($g);
        }
        if ($infoDesk) {
            $g = new Group('Info Desk', 'info-desk', 'ROLE_STAFF');
            $this->em->persist($g);
            $u->addGroup($g);
        }
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function supportWith(User $subject): Conversation
    {
        $c = new Conversation(ConversationType::SUPPORT, $subject);
        $this->em->persist($c);
        $this->em->flush();

        return $c;
    }

    public function testInfoDeskAndAdminAndSubjectLabels(): void
    {
        $subject = $this->user('vol');
        $conversation = $this->supportWith($subject);

        $infoDesk = $this->user('desk', null, infoDesk: true);
        $admin = $this->user('boss', 'ROLE_ADMIN');

        $fromSubject = new ChatMessage($conversation, $subject, 'help');
        $fromDesk = new ChatMessage($conversation, $infoDesk, 'hi');
        $fromAdmin = new ChatMessage($conversation, $admin, 'stepping in');
        $system = new ChatMessage($conversation, null, 'welcome');

        self::assertSame('vol', $this->chat()->senderLabel($fromSubject));
        self::assertSame('Info Desk - desk', $this->chat()->senderLabel($fromDesk));
        self::assertSame('Administrator - boss', $this->chat()->senderLabel($fromAdmin));
        self::assertSame('Info Desk Team', $this->chat()->senderLabel($system));
    }
}
