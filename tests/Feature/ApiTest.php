<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;

final class ApiTest extends DatabaseWebTestCase
{
    private ?\App\Entity\Department $dept = null;

    private function department(): \App\Entity\Department
    {
        if ($this->dept === null) {
            $this->dept = new \App\Entity\Department('Dept '.bin2hex(random_bytes(3)), 'dept-'.bin2hex(random_bytes(3)));
            $this->em->persist($this->dept);
        }

        return $this->dept;
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

    /**
     * The feed emits absolute UTC instants (a trailing Z) so every device fires the reminder at
     * the same moment. A floating local time is reinterpreted in the device's own timezone and
     * fires at the wrong one.
     */
    public function testIcalEmitsAbsoluteUtcInstants(): void
    {
        $user = $this->makeUser('ical-key', ['export:ical']);

        $type = new VolunteerType('Heaven');
        $this->em->persist($type);

        $shift = (new Shift())
            ->setTitle('Night Watch')
            ->setStartsAt(new \DateTimeImmutable('2026-08-15 20:00:00', new \DateTimeZone('UTC')))
            ->setEndsAt(new \DateTimeImmutable('2026-08-16 04:00:00', new \DateTimeZone('UTC')))
            ->setDepartment($this->department());
        $this->em->persist($shift);
        $this->em->persist(new ShiftEntry($shift, $type, $user));
        $this->em->flush();

        $this->get('/api/ical');
        self::assertResponseStatusCodeSame(401);

        $this->get('/api/ical', 'ical-key');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('DTSTART:20260815T200000Z', $body);
        self::assertStringContainsString('DTEND:20260816T040000Z', $body);
        self::assertStringContainsString('DTSTAMP:', $body);
        self::assertDoesNotMatchRegularExpression('/DTSTART:\d{8}T\d{6}(?!Z)/', $body);
    }
}
