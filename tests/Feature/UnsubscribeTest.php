<?php

namespace App\Tests\Feature;

use App\Entity\Settings;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class UnsubscribeTest extends DatabaseWebTestCase
{
    private function makeSubscriber(): User
    {
        $user = new User();
        $user->setName('subbie')->setEmail('subbie@example.com')->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x')->setUnsubscribeToken('tok-'.bin2hex(random_bytes(8)));
        $settings = new Settings($user);
        $settings->setEmailNews(true);
        $user->setSettings($settings);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testUnknownTokenIs404(): void
    {
        $this->client->request('GET', '/unsubscribe/nope?type=news');
        self::assertResponseStatusCodeSame(404);
    }

    public function testConfirmThenUnsubscribeWithoutLogin(): void
    {
        $user = $this->makeSubscriber();
        $token = $user->getUnsubscribeToken();

        $this->client->request('GET', '/unsubscribe/'.$token.'?type=news');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/unsubscribe/'.$token, ['type' => 'news']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        self::assertFalse($reloaded->getSettings()->isEmailNews());
    }
}
