<?php

namespace App\Tests\Integration;

use App\Entity\PersonalData;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestCase;

/**
 * The info-desk locate lookup is deliberately narrow to resist account mining: emails match only
 * in full, a digit string is a registration number (never the database id), and only names are
 * matched as substrings.
 */
final class UserLocateTest extends DatabaseTestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = static::getContainer()->get(UserRepository::class);

        $alice = $this->makeUser('alice', 'alice@example.com', 4242);
        $this->makeUser('bob', 'bob@other.org', null);
        $this->em->flush();
        // Referenced so the id-is-not-searchable test can query a real, badge-less user's id.
        $this->bobId = $this->em->getRepository(User::class)->findOneBy(['name' => 'bob'])->getId();
        self::assertNotSame(4242, $this->bobId, 'guard: the id under test must differ from the seeded badge number');
    }

    private int $bobId;

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

    public function testExactEmailMatchesRegardlessOfCase(): void
    {
        self::assertSame(['alice'], $this->names($this->users->locate('ALICE@Example.com')));
    }

    public function testPartialEmailNeverMatches(): void
    {
        // A truncated address (still containing '@') is matched exactly, so it finds nothing.
        self::assertSame([], $this->users->locate('alice@exam'));
        // A bare domain fragment (no '@') falls into name search and cannot reach the email column.
        self::assertSame([], $this->users->locate('example.com'));
    }

    public function testRegistrationNumberMatchesExactly(): void
    {
        self::assertSame(['alice'], $this->names($this->users->locate('4242')));
        self::assertSame([], $this->users->locate('42'));
    }

    public function testDatabaseIdIsNotSearchable(): void
    {
        // Querying a real user's id (as digits) must be read as a registration number, not the id.
        self::assertSame([], $this->users->locate((string) $this->bobId));
    }

    public function testNameMatchesAsSubstring(): void
    {
        self::assertSame(['alice'], $this->names($this->users->locate('lic')));
    }
}
