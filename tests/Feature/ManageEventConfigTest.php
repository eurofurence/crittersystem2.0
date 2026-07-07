<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageEventConfigTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: '.$e->getMessage());
        }

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

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
     * Regression: with a date already stored (as DATE_ATOM, "+00:00" offset),
     * rendering the form used to throw because the value's timezone name
     * ("+00:00") did not match the field's model_timezone ("UTC").
     */
    public function testRendersWithAStoredDate(): void
    {
        $this->store()->set(EventConfigStore::KEY_EVENT_START, '2026-09-01T00:00:00+00:00');
        $this->store()->flush();

        $this->loginAdmin();
        $this->client->request('GET', '/manage/event-config');

        self::assertResponseIsSuccessful();
    }

    public function testSavingDatesRoundTripsWithoutError(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/manage/event-config');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['event_config[eventStart]'] = '2026-09-01T09:00';
        $this->client->submit($form);

        // Saves and redirects back to the config page…
        self::assertResponseRedirects('/manage/event-config');
        // …and the reload (which re-reads the stored date) renders cleanly.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        self::assertSame(
            '2026-09-01T09:00:00+00:00',
            $this->store()->getDate(EventConfigStore::KEY_EVENT_START)?->format(\DATE_ATOM),
        );
    }
}
