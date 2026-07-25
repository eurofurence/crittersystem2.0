<?php

namespace App\Tests\Integration;

use App\Entity\PersonalData;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestCase;

/**
 * The management search (user admin, badge assignment, shift staffing) must resist
 * account mining exactly as locate() does: a partial email never enumerates
 * addresses, and an all-digit query is a badge number, never the internal id. A
 * substring email search used to return a page of addresses and defeat the PII gate.
 */
final class UserSearchTest extends DatabaseTestCase
{
    private UserRepository $users;
    private int $bobId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = static::getContainer()->get(UserRepository::class);

        $this->makeUser('alice', 'alice@example.com', 4242);
        $this->makeUser('bob', 'bob@other.org', null);
        $this->em->flush();
        $this->bobId = $this->em->getRepository(User::class)->findOneBy(['name' => 'bob'])->getId();
        self::assertNotSame(4242, $this->bobId, 'guard: the id under test must differ from the seeded badge number');
    }

    private function makeUser(string $name, string $email, ?int $badge): User
    {
        $user = new User();
        $user->setName($name)->setEmail($email)->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $pd = new PersonalData($user);
        $pd->setBadgeNumber($badge);
        $user->setPersonalData($pd);
        $this->em->persist($user);
        $this->em->persist($pd);

        return $user;
    }

    /** @param User[] $result */
    private function names(array $result): array
    {
        return array_map(static fn (User $u): string => $u->getName(), $result);
    }

    public function testPartialEmailNeverEnumerates(): void
    {
        // The property the gate depends on: a substring of an address returns nothing.
        self::assertSame([], $this->users->search('@example'));
        self::assertSame([], $this->users->search('alice@exam'));
        // A bare domain fragment falls into name search and cannot reach the email column.
        self::assertSame([], $this->users->search('example.com'));
    }

    public function testExactEmailMatchesRegardlessOfCase(): void
    {
        self::assertSame(['alice'], $this->names($this->users->search('ALICE@Example.com')));
    }

    public function testAllDigitQueryMatchesBadgeNotDatabaseId(): void
    {
        self::assertSame(['alice'], $this->names($this->users->search('4242')));
        self::assertSame([], $this->users->search('42'));
        // Querying a real user's id (as digits) must be read as a badge number, not the id.
        self::assertSame([], $this->users->search((string) $this->bobId));
    }

    public function testNameStillMatchesAsSubstring(): void
    {
        self::assertSame(['alice'], $this->names($this->users->search('lic')));
    }
}
