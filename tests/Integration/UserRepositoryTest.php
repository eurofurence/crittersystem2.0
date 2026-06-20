<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRepositoryTest extends DatabaseTestCase
{
    private function makeUser(string $name, string $email, string $plainPassword): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setName($name)
            ->setEmail($email)
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        return $user;
    }

    public function testFindByUsernameOrEmailAndUpgradePassword(): void
    {
        /** @var UserRepository $repository */
        $repository = $this->em->getRepository(User::class);

        $user = $this->makeUser('tester', 'tester@example.com', 'secret123');
        $this->em->persist($user);
        $this->em->flush();

        self::assertSame($user, $repository->findOneByUsernameOrEmail('tester'));
        self::assertNotNull($repository->findOneByUsernameOrEmail('tester@example.com'));
        self::assertNull($repository->findOneByUsernameOrEmail('nobody'));
        self::assertSame(1, $repository->countAll());

        $repository->upgradePassword($user, 'rehashed-value');
        $this->em->clear();

        $reloaded = $repository->findOneByUsernameOrEmail('tester');
        self::assertNotNull($reloaded);
        self::assertSame('rehashed-value', $reloaded->getPassword());
    }

    public function testTimestampsArePopulatedOnPersist(): void
    {
        $user = $this->makeUser('stamp', 'stamp@example.com', 'secret123');
        $this->em->persist($user);
        $this->em->flush();

        self::assertNotNull($user->getCreatedAt());
        self::assertNotNull($user->getUpdatedAt());
    }
}
