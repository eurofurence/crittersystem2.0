<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Info Desk queue fragment.
 *
 * It exists so the queue can be re-fetched on its own when a conversation is opened, claimed or
 * closed. It is rendered per viewer - "claimed by you" is a different list for every responder -
 * which is why the queue signal carries no markup, and why this endpoint has to be gated by the same
 * privilege that puts the queue topic in a subscriber token.
 */
final class InfoDeskQueueFrameTest extends DatabaseWebTestCase
{
    private function user(string $name, string ...$privileges): User
    {
        $group = new Group(ucfirst($name), $name.'-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach ($privileges as $privilege) {
            $entity = new Privilege($privilege);
            $this->em->persist($entity);
            $group->addPrivilege($entity);
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

    /**
     * The endpoint answers with a fragment, never a document: the live region assigns the response
     * straight to innerHTML.
     */
    public function testAResponderGetsTheQueueFragment(): void
    {
        $this->client->loginUser($this->user('responder', 'message:use', 'chat:claim'));

        $this->client->request('GET', '/messages/queue/frame', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase('<html', $body);
    }

    /** Without the claim privilege there is no queue to see, and no topic either. */
    public function testAVolunteerCannotReachTheQueueFragment(): void
    {
        $this->client->loginUser($this->user('volunteer', 'message:use'));

        $this->client->request('GET', '/messages/queue/frame', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
