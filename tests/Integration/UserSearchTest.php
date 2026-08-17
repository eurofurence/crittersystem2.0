<?php

namespace App\Tests\Integration;

use App\Entity\PersonalData;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestCase;

/**
 * The management search (user admin, badge assignment, shift staffing) must resist
 * account mining exactly as locate() does: a partial email never enumerates addresses, and an
 * all-digit query is a badge number, never the internal id. A substring email search would hand
 * back a page of addresses and defeat the PII gate.
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

    /**
     * The property the PII gate depends on: a substring of an address returns nothing, and a bare
     * domain fragment falls into name search, which cannot reach the email column.
     */
    public function testPartialEmailNeverEnumerates(): void
    {
        self::assertSame([], $this->users->search('@example'));
        self::assertSame([], $this->users->search('alice@exam'));
        self::assertSame([], $this->users->search('example.com'));
    }

    public function testExactEmailMatchesRegardlessOfCase(): void
    {
        self::assertSame(['alice'], $this->names($this->users->search('ALICE@Example.com')));
    }

    /** A digit string is read as a badge number, so a real user's id matches nobody. */
    public function testAllDigitQueryMatchesBadgeNotDatabaseId(): void
    {
        self::assertSame(['alice'], $this->names($this->users->search('4242')));
        self::assertSame([], $this->users->search('42'));
        self::assertSame([], $this->users->search((string) $this->bobId));
    }

    public function testNameStillMatchesAsSubstring(): void
    {
        self::assertSame(['alice'], $this->names($this->users->search('lic')));
    }
}
