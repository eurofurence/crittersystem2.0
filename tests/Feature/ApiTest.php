<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
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
        $group = new Group(20, 'API group', 'api-group');
        foreach ($privileges as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('apiuser')->setEmail('api@example.com')->setPassword('x')->setApiKey($apiKey);
        $user->addGroup($group);
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
        $this->makeUser('export-key', ['shifts_json_export']);

        $this->get('/api/shifts-json-export');
        self::assertResponseStatusCodeSame(401);

        $this->get('/api/shifts-json-export', 'export-key');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('data', json_decode((string) $this->client->getResponse()->getContent(), true));
    }
}
