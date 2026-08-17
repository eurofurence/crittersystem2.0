<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageEventConfigTest extends DatabaseWebTestCase
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
        $user->setName('cfgadmin')->setEmail('cfgadmin@example.com')->setApiKey(bin2hex(random_bytes(16)));
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

    /**
     * Regression: a stored date is written as DATE_ATOM with a "+00:00" offset. The form throws on
     * render unless it is read back in the *named* "UTC" zone, because the field's model_timezone
     * ("UTC") must match the value's timezone name.
     */
    public function testRendersWithAStoredDate(): void
    {
        $this->store()->set(EventConfigStore::KEY_EVENT_START, '2026-09-01T00:00:00+00:00');
        $this->store()->flush();

        $this->loginAdmin();
        $this->client->request('GET', '/manage/event-config');

        self::assertResponseIsSuccessful();
    }

    /**
     * Saving redirects back to the config page, and the reload, which re-reads the stored date,
     * renders cleanly.
     */
    public function testSavingDatesRoundTripsWithoutError(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/event-config');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['event_config[eventStart]'] = '2026-09-01T09:00';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/event-config');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        self::assertSame(
            '2026-09-01T09:00:00+00:00',
            $this->store()->getDate(EventConfigStore::KEY_EVENT_START)?->format(\DATE_ATOM),
        );
    }
}
