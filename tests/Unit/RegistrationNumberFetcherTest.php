<?php

namespace App\Tests\Unit;

use App\Entity\PersonalData;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Sso\RegistrationApiSettings;
use App\Sso\RegistrationNumberFetcher;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

final class RegistrationNumberFetcherTest extends TestCase
{
    private function settings(?string $url): RegistrationApiSettings
    {
        $config = $this->createStub(EventConfigStore::class);
        $config->method('getString')->willReturn($url ?? '');

        return new RegistrationApiSettings($config);
    }

    private function userWithBadge(?int $badge): User
    {
        $user = new User();
        $user->setName('u')->setEmail('u@example.com');
        $pd = new PersonalData($user);
        $pd->setBadgeNumber($badge);
        $user->setPersonalData($pd);

        return $user;
    }

    /** @param array<string, mixed> $parsed */
    private function provider(array $parsed, bool $expectCall = true): GenericProvider
    {
        $provider = $this->createMock(GenericProvider::class);
        $provider->method('getAuthenticatedRequest')->willReturn($this->createStub(RequestInterface::class));
        $provider->expects($expectCall ? self::once() : self::never())
            ->method('getParsedResponse')
            ->willReturn($parsed);

        return $provider;
    }

    public function testStoresTheFirstIdFromTheApi(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $user = $this->userWithBadge(null);
        $fetcher = new RegistrationNumberFetcher($this->settings('https://reg/api'), $em, new NullLogger());
        $fetcher->updateFor($user, $this->provider(['ids' => [12345, 999]]), $this->createStub(AccessTokenInterface::class));

        self::assertSame(12345, $user->getPersonalData()->getBadgeNumber());
    }

    public function testAZeroOrEmptyAnswerLeavesTheNumberBlank(): void
    {
        foreach ([['ids' => [0]], ['ids' => []], []] as $parsed) {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::never())->method('flush');

            $user = $this->userWithBadge(null);
            $fetcher = new RegistrationNumberFetcher($this->settings('https://reg/api'), $em, new NullLogger());
            $fetcher->updateFor($user, $this->provider($parsed), $this->createStub(AccessTokenInterface::class));

            self::assertNull($user->getPersonalData()->getBadgeNumber());
        }
    }

    public function testAnExistingNumberIsNeverOverwritten(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $user = $this->userWithBadge(999);
        $fetcher = new RegistrationNumberFetcher($this->settings('https://reg/api'), $em, new NullLogger());
        $fetcher->updateFor($user, $this->provider([], expectCall: false), $this->createStub(AccessTokenInterface::class));

        self::assertSame(999, $user->getPersonalData()->getBadgeNumber());
    }

    public function testTheLookupIsSkippedWhenNoEndpointIsConfigured(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $user = $this->userWithBadge(null);
        $fetcher = new RegistrationNumberFetcher($this->settings(null), $em, new NullLogger());
        $fetcher->updateFor($user, $this->provider([], expectCall: false), $this->createStub(AccessTokenInterface::class));

        self::assertNull($user->getPersonalData()->getBadgeNumber());
    }
}
