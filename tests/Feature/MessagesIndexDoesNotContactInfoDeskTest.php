<?php

namespace App\Tests\Feature;

use App\Entity\Conversation;
use App\Entity\Group;
use App\Entity\Notification;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Opening the messages list must not contact the Info Desk.
 *
 * The list rendered the "Info Desk Team" entry by asking for the user's support conversation, and
 * asking for it created it: a new conversation, an entry in the Info Desk queue, and a notification
 * telling the user their message had been sent - before they had written anything or even clicked
 * anything. Every volunteer who so much as opened /messages queued a conversation for the Info Desk
 * to work through.
 *
 * Contacting the Info Desk is now what the button does, not what the page does.
 */
final class MessagesIndexDoesNotContactInfoDeskTest extends DatabaseWebTestCase
{
    private function volunteer(string $name = 'curious'): User
    {
        $group = new Group('Volunteer', 'volunteer-'.bin2hex(random_bytes(2)), null);
        foreach (['message:use', 'news:view'] as $name2) {
            $privilege = new Privilege($name2);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function conversationCount(): int
    {
        return (int) $this->em->getRepository(Conversation::class)->count([]);
    }

    private function notificationCount(): int
    {
        return (int) $this->em->getRepository(Notification::class)->count([]);
    }

    public function testOpeningTheListCreatesNothing(): void
    {
        $this->client->loginUser($this->volunteer());

        $this->client->request('GET', '/messages');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->conversationCount(), 'opening the list must not open a conversation');
        self::assertSame(0, $this->notificationCount(), 'and must not notify anyone');
    }

    /** Opening it repeatedly - which is what users do - must stay at nothing. */
    public function testOpeningTheListRepeatedlyStillCreatesNothing(): void
    {
        $this->client->loginUser($this->volunteer());

        $this->client->request('GET', '/messages');
        $this->client->request('GET', '/messages');
        $this->client->request('GET', '/messages');

        self::assertSame(0, $this->conversationCount());
        self::assertSame(0, $this->notificationCount());
    }

    /** The entry points at the action, not at a conversation that does not exist yet. */
    public function testTheInfoDeskEntryLinksToTheActionThatOpensTheConversation(): void
    {
        $this->client->loginUser($this->volunteer());
        $crawler = $this->client->request('GET', '/messages');

        // Scoped to the list entry: the navbar has its own "Ask Info Desk" link to the same action,
        // and matching that instead would pass whatever the list does.
        self::assertCount(
            1,
            $crawler->filter('a.list-group-item[href="/messages/info-desk"]'),
            'the Info Desk entry must link to the action, so the conversation opens on click',
        );
    }

    /** Clicking it is what opens the conversation. */
    public function testClickingTheEntryOpensTheConversation(): void
    {
        $this->client->loginUser($this->volunteer());

        $this->client->request('GET', '/messages/info-desk');

        self::assertResponseRedirects();
        self::assertSame(1, $this->conversationCount());
    }

    /** Clicking twice reuses the open conversation rather than stacking them up. */
    public function testClickingTwiceReusesTheSameConversation(): void
    {
        $this->client->loginUser($this->volunteer());

        $this->client->request('GET', '/messages/info-desk');
        $this->client->request('GET', '/messages/info-desk');

        self::assertSame(1, $this->conversationCount());
    }

    /**
     * The "your message was sent" notification belongs to sending a message.
     *
     * It fired on creation, so a user who opened the conversation and wrote nothing was told their
     * message had been sent. The first real message still notifies, through the ordinary posting
     * path.
     */
    public function testOpeningTheConversationDoesNotClaimAMessageWasSent(): void
    {
        $this->client->loginUser($this->volunteer());

        $this->client->request('GET', '/messages/info-desk');

        self::assertSame(0, $this->notificationCount(), 'nothing was sent yet, so nothing to report');
    }

    public function testSendingTheFirstMessageDoesNotify(): void
    {
        $user = $this->volunteer();
        $this->client->loginUser($user);

        $this->client->request('GET', '/messages/info-desk');
        $conversation = $this->em->getRepository(Conversation::class)->findOneBy(['subject' => $user]);
        self::assertNotNull($conversation);

        $crawler = $this->client->request('GET', '/messages/'.$conversation->getUuid());
        $token = $crawler->filter('form[action$="/send"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/messages/'.$conversation->getUuid().'/send', [
            '_token' => $token,
            'text' => 'I lost my badge.',
        ]);

        self::assertGreaterThan(0, $this->notificationCount(), 'sending a message must reach the Info Desk');
    }
}
