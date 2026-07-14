<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Contacting Info Desk reuses the messaging model and shows the welcome. */
final class ContactInfoDeskTest extends DatabaseWebTestCase
{
    public function testContactInfoDeskOpensSupportWithWelcome(): void
    {
        static::getContainer()->get(EventConfigStore::class)->set(EventConfigStore::KEY_INFODESK_WELCOME, 'Hi! Info Desk here.');

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
        $this->client->request('GET', '/messages/info-desk');
        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Hi! Info Desk here.');
        self::assertCount(1, static::getContainer()->get(ConversationRepository::class)->findAll());
    }
}
