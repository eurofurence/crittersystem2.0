<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\TelegramConfiguration;
use App\Entity\User;
use App\Repository\TelegramConfigurationRepository;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageTelegramConfigTest extends DatabaseWebTestCase
{
    private function loginAdmin(): void
    {
        $group = new Group('Admins', 'admins');
        $privilege = new Privilege('config:telegram');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('tgadmin')->setEmail('tgadmin@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    public function testBotUsernameSavesWithLeadingAtStripped(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/telegram');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['enabled']->tick();
        $form['api_endpoint'] = 'https://bot.example/api';
        $form['bot_username'] = '@MyEventBot';
        $this->client->submit($form);
        self::assertResponseRedirects('/manage/telegram');

        $this->em->clear();
        $config = static::getContainer()->get(TelegramConfigurationRepository::class)->current();
        self::assertInstanceOf(TelegramConfiguration::class, $config);
        self::assertSame('MyEventBot', $config->getBotUsername());
    }

    public function testBlankBotUsernameStoredAsNull(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/telegram');

        $form = $crawler->selectButton('Save')->form();
        $form['api_endpoint'] = 'https://bot.example/api';
        $form['bot_username'] = '   ';
        $this->client->submit($form);

        $this->em->clear();
        $config = static::getContainer()->get(TelegramConfigurationRepository::class)->current();
        self::assertNull($config->getBotUsername());
    }
}
