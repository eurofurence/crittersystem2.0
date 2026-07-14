<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageOperationsConfigTest extends DatabaseWebTestCase
{
    private function loginAdmin(): void
    {
        $group = new Group('Admins', 'admins');
        $privilege = new Privilege('config:event');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('opsadmin')->setEmail('opsadmin@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function store(): EventConfigStore
    {
        return static::getContainer()->get(EventConfigStore::class);
    }

    public function testRendersWithDefaults(): void
    {
        $this->loginAdmin();
        $this->client->request('GET', '/manage/operations');

        self::assertResponseIsSuccessful();
    }

    public function testSavingRoundTrips(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/operations');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['operations_config[noShowThreshold]'] = '3';
        $form['operations_config[recommendedMaxHours]'] = '25';
        $form['operations_config[messagesEnabled]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/operations');

        self::assertSame(3, $this->store()->getInt(EventConfigStore::KEY_BAN_NOSHOW_THRESHOLD, 2));
        self::assertSame(25, $this->store()->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, 20));
        self::assertTrue($this->store()->getBool(EventConfigStore::KEY_MESSAGES_ENABLED, false));
    }
}
