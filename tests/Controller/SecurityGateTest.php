<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies the access_control gate: anonymous users are redirected to the login page, and the login
 * page itself stays public.
 *
 * The gate is pure configuration, but rendering the login page is not - it reads event_config for
 * the site's own settings. Building the schema is therefore part of the test, not incidental to it:
 * without it the page answers 500 and the assertion fails for a reason that has nothing to do with
 * the gate.
 */
final class SecurityGateTest extends DatabaseWebTestCase
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
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button[type="submit"]', 'Sign in');
    }
}
