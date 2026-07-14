<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\TelegramConfiguration;
use App\Entity\TelegramLinkRequest;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TelegramLinkTest extends DatabaseWebTestCase
{
    private function enableTelegram(): void
    {
        $config = new TelegramConfiguration();
        $config->setEnabled(true)->setApiEndpoint('https://bot.example')->setApiKey('secret-key');
        $this->em->persist($config);
        $this->em->flush();
    }

    private function makeVolunteer(): User
    {
        $group = new Group('Volunteers', 'volunteers');
        $priv = new Privilege('telegram:link');
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('tg')->setEmail('tg@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testLinkViaDummyBotThenUnlink(): void
    {
        $this->enableTelegram();
        $user = $this->makeVolunteer();
        $this->client->loginUser($user);

        // Start a link -> a pending request with a code is created.
        $this->client->request('POST', '/profile/telegram/start');
        self::assertResponseRedirects('/profile/telegram');
        $request = $this->em->getRepository(TelegramLinkRequest::class)->findOneBy(['user' => $user]);
        self::assertNotNull($request);
        self::assertTrue($request->isPending());

        // The (dummy) bot confirms the code.
        $this->client->request('POST', '/telegram/dummy/confirm', [
            'code' => $request->getCode(), 'telegram_id' => '555111', 'handle' => '@tguser',
        ]);
        self::assertResponseIsSuccessful();

        // Live status now reports linked.
        $this->client->request('GET', '/profile/telegram/status');
        self::assertJsonStringEqualsJsonString('{"linked":true}', $this->client->getResponse()->getContent());

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertTrue($reloaded->isTelegramLinked());
        self::assertSame('555111', $reloaded->getTelegramId());

        $crawler = $this->client->request('GET', '/profile/telegram');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/profile/telegram/unlink', ['_token' => $token]);
        self::assertResponseRedirects('/profile/telegram');

        $this->em->clear();
        self::assertFalse($this->em->getRepository(User::class)->find($user->getId())->isTelegramLinked());
    }

    public function testExpiredCodeDoesNotLink(): void
    {
        $this->enableTelegram();
        $user = $this->makeVolunteer();

        $request = new TelegramLinkRequest($user, 'EXPIREDCODE');
        $this->em->persist($request);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE telegram_link_requests SET expires_at = :old WHERE code = :c',
            ['old' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:sP'), 'c' => 'EXPIREDCODE'],
        );
        $this->em->clear();

        $this->client->request('POST', '/telegram/dummy/confirm', ['code' => 'EXPIREDCODE', 'telegram_id' => '9']);
        self::assertJsonStringEqualsJsonString('{"ok":false}', $this->client->getResponse()->getContent());
    }
}
