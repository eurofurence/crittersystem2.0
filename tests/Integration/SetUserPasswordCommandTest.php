<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetUserPasswordCommandTest extends DatabaseTestCase
{
    private function makeUser(string $name, string $plainPassword): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)
            ->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, $plainPassword));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function runPasswordCommand(array $input): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:user:password'));
        $tester->execute($input);

        return $tester;
    }

    public function testResetsPasswordForExistingUser(): void
    {
        $this->makeUser('pwtest', 'old-password');

        $tester = $this->runPasswordCommand(['username' => 'pwtest', '--password' => 'new-secret']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->em->getRepository(User::class)->findOneBy(['name' => 'pwtest']);

        self::assertNotNull($user);
        self::assertTrue($hasher->isPasswordValid($user, 'new-secret'));
        self::assertFalse($hasher->isPasswordValid($user, 'old-password'));
    }

    public function testAcceptsEmailIdentifier(): void
    {
        $this->makeUser('byemail', 'old-password');

        $tester = $this->runPasswordCommand(['username' => 'byemail@example.com', '--password' => 'changed']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->em->getRepository(User::class)->findOneBy(['name' => 'byemail']);
        self::assertTrue($hasher->isPasswordValid($user, 'changed'));
    }

    public function testFailsForUnknownUser(): void
    {
        $tester = $this->runPasswordCommand(['username' => 'ghost', '--password' => 'whatever']);

        self::assertSame(1, $tester->getStatusCode());
    }
}
