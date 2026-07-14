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

        foreach (self::kitUrls() as $name => $url) {
            yield $name => $url;
        }
    }

    /**
     * Every UI kit page. These are developer tools: they exist only outside prod (the
     * App\Dev\ services are not registered there, so the routes do not exist at all) and
     * carry #[IsGranted('global:admin')] on top. See DevKitAccessTest for the privilege gate.
     *
     * @return iterable<string, array{string}>
     */
    public static function kitUrls(): iterable
    {
        yield 'data kit' => ['/dev/ui/data-kit'];
        yield 'form kit' => ['/dev/kit'];
        yield 'modal kit' => ['/dev/ui/modal-kit'];
        yield 'navigation kit' => ['/dev/ui/navigation-kit'];
        yield 'notification kit' => ['/dev/ui/notification-kit'];
        yield 'theme kit' => ['/dev/kit/themes'];
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
