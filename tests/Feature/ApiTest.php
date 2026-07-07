<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: '.$e->getMessage());
        }

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /** @param string[] $privileges */
    private function makeUser(string $apiKey, array $privileges = []): User
    {
        $group = new Group('API group', 'api-group');
        foreach ($privileges as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('apiuser')->setEmail('api@example.com')->setPassword('x')->setApiKey($apiKey);
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function get(string $uri, ?string $key = null): void
    {
        $server = $key !== null ? ['HTTP_AUTHORIZATION' => 'Bearer '.$key] : [];
        $this->client->request('GET', $uri, [], [], $server);
    }

    public function testPublicInfoEndpointIsOpen(): void
    {
        $this->get('/api/v0-beta/info');

        self::assertResponseIsSuccessful();
        self::assertJson((string) $this->client->getResponse()->getContent());
        self::assertArrayHasKey('timeline', json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testSelfRequiresApiKey(): void
    {
        $this->makeUser('key-self-123');

        $this->get('/api/v0-beta/users/self');
        self::assertResponseStatusCodeSame(401);

        $this->get('/api/v0-beta/users/self', 'key-self-123');
        self::assertResponseIsSuccessful();
        self::assertSame('apiuser', json_decode((string) $this->client->getResponse()->getContent(), true)['name']);
    }

    public function testInvalidApiKeyIsRejected(): void
    {
        $this->makeUser('valid-key');
        $this->get('/api/v0-beta/users/self', 'wrong-key');

        self::assertResponseStatusCodeSame(401);
    }

    public function testShiftsJsonExportRequiresPrivilege(): void
    {
        $this->makeUser('export-key', ['export:shifts']);

        $this->get('/api/shifts-json-export');
        self::assertResponseStatusCodeSame(401);

        $this->get('/api/shifts-json-export', 'export-key');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('data', json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testIcalEmitsAbsoluteUtcInstants(): void
    {
        $user = $this->makeUser('ical-key', ['export:ical']);

        $type = new VolunteerType('Heaven');
        $this->em->persist($type);

        $shift = (new Shift())
            ->setTitle('Night Watch')
            ->setStartsAt(new \DateTimeImmutable('2026-08-15 20:00:00', new \DateTimeZone('UTC')))
            ->setEndsAt(new \DateTimeImmutable('2026-08-16 04:00:00', new \DateTimeZone('UTC')));
        $this->em->persist($shift);
        $this->em->persist(new ShiftEntry($shift, $type, $user));
        $this->em->flush();

        $this->get('/api/ical');
        self::assertResponseStatusCodeSame(401);

        $this->get('/api/ical', 'ical-key');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        // Absolute UTC instants (trailing Z) so every device fires the reminder
        // at the correct moment — never floating local times (the critter 1.0 bug).
        self::assertStringContainsString('DTSTART:20260815T200000Z', $body);
        self::assertStringContainsString('DTEND:20260816T040000Z', $body);
        self::assertStringContainsString('DTSTAMP:', $body);
        self::assertDoesNotMatchRegularExpression('/DTSTART:\d{8}T\d{6}(?!Z)/', $body);
    }
}
