<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Chat UI end-to-end: support conversation and sending. */
final class ChatPageTest extends DatabaseWebTestCase
{
    private function login(): User
    {
        $group = new Group('Users', 'users', null);
        $priv = new Privilege('message:use');
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('Vera')->setEmail('vera@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    public function testIndexShowsInfoDeskTeamDefaultContact(): void
    {
        $this->login();
        $this->client->request('GET', '/messages');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Info Desk Team');
    }

    public function testUserCanSendToSupportConversation(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/messages');
        $link = $crawler->filter('a:contains("Info Desk Team")')->link();
        $crawler = $this->client->click($link);
        self::assertResponseIsSuccessful();

        $conversation = static::getContainer()->get(ConversationRepository::class)->findAll()[0];
        $token = $crawler->filter('form[action*="/send"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/messages/'.$conversation->getUuid().'/send', [
            '_token' => $token,
            'text' => 'I need help with my badge',
        ]);
        self::assertResponseRedirects('/messages/'.$conversation->getUuid());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'I need help with my badge');
    }
}
