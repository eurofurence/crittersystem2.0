<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UsernameGenerator;
use PHPUnit\Framework\TestCase;

final class UsernameGeneratorTest extends TestCase
{
    public function testUniqueWhenFree(): void
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        self::assertSame('alice', (new UsernameGenerator($repo))->unique('alice'));
    }

    public function testSuffixesOnCollision(): void
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => $criteria['name'] === 'taken' ? new User() : null,
        );

        $result = (new UsernameGenerator($repo))->unique('taken');
        self::assertNotSame('taken', $result);
        self::assertMatchesRegularExpression('/^taken_\d{2}$/', $result);
    }
}
