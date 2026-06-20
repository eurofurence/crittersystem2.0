<?php

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifies the access_control gate without needing a database: anonymous users
 * are redirected to the login page, and the login page itself stays public.
 */
final class SecurityGateTest extends WebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function gatedUrls(): iterable
    {
        yield 'dashboard' => ['/dashboard'];
        yield 'home' => ['/home'];
        yield 'data kit' => ['/dev/ui/data-kit'];
        yield 'form kit' => ['/dev/kit'];
        yield 'modal kit' => ['/dev/ui/modal-kit'];
        yield 'navigation kit' => ['/dev/ui/navigation-kit'];
        yield 'notification kit' => ['/dev/ui/notification-kit'];
    }

    #[DataProvider('gatedUrls')]
    public function testGatedPagesRedirectAnonymousToLogin(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url);

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button[type="submit"]', 'Sign in');
    }
}
