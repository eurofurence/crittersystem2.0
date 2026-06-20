<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\UserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserProviderTest extends TestCase
{
    public function testLoadByIdentifierReturnsUser(): void
    {
        $user = new User();
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())
            ->method('findOneByUsernameOrEmail')
            ->with('admin')
            ->willReturn($user);

        $provider = new UserProvider($repository);

        self::assertSame($user, $provider->loadUserByIdentifier('admin'));
    }

    public function testLoadByIdentifierThrowsWhenMissing(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneByUsernameOrEmail')->willReturn(null);

        $provider = new UserProvider($repository);

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('ghost');
    }

    public function testRefreshUserReloadsById(): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 7);
        $reloaded = new User();

        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())->method('find')->with(7)->willReturn($reloaded);

        $provider = new UserProvider($repository);

        self::assertSame($reloaded, $provider->refreshUser($user));
    }

    public function testRefreshUserRejectsUnsupportedClass(): void
    {
        $provider = new UserProvider($this->createStub(UserRepository::class));

        $other = new class implements UserInterface {
            public function getRoles(): array
            {
                return [];
            }

            public function getUserIdentifier(): string
            {
                return 'x';
            }
        };

        $this->expectException(UnsupportedUserException::class);
        $provider->refreshUser($other);
    }

    public function testSupportsClass(): void
    {
        $provider = new UserProvider($this->createStub(UserRepository::class));

        self::assertTrue($provider->supportsClass(User::class));
        self::assertFalse($provider->supportsClass(\stdClass::class));
    }
}
