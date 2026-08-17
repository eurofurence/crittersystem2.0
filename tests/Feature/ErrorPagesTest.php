<?php

namespace App\Tests\Feature;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The branded error template. It is rendered directly here rather than through a
 * real HTTP error: in the test environment DebugBundle dumps exceptions before
 * the error renderer runs, so a functional 404/403 never reaches the template.
 * Symfony's status-code -> @Twig/Exception/error{code} lookup (falling back to
 * error.html.twig) is framework convention; what needs covering is that our
 * override is registered and renders the right, non-leaking copy per status.
 */
final class ErrorPagesTest extends KernelTestCase
{
    /** Pushes a request context so base.html.twig (routing, locale, theme) resolves. */
    private function renderError(int $code): string
    {
        static::getContainer()->get('request_stack')->push(Request::create('/anywhere'));

        return static::getContainer()->get('twig')->render('@Twig/Exception/error.html.twig', [
            'status_code' => $code,
            'status_text' => 'Test',
            'exception' => FlattenException::createFromThrowable(new \RuntimeException('boom-secret-detail')),
        ]);
    }

    public function testOverrideTemplateIsRegistered(): void
    {
        self::bootKernel();

        $exists = static::getContainer()->get('twig')->getLoader()->exists('@Twig/Exception/error.html.twig');
        self::assertTrue($exists, 'the app overrides @Twig/Exception/error.html.twig');
    }

    /** The 403 page carries the permission copy and renders inside the shared site layout, not bare. */
    public function testForbiddenShowsPermissionCopyInsideChrome(): void
    {
        self::bootKernel();
        $html = $this->renderError(403);

        self::assertStringContainsString('data-status="403"', $html);
        self::assertStringContainsString('have permission', $html);
        self::assertStringContainsString('navbar-brand', $html);
    }

    public function testNotFoundShowsItsOwnCopy(): void
    {
        self::bootKernel();
        $html = $this->renderError(404);

        self::assertStringContainsString('data-status="404"', $html);
        self::assertStringContainsString('Page not found', $html);
    }

    /** The 500 page carries friendly copy: no exception message and no class name reaches the user. */
    public function testServerErrorShowsFriendlyCopyAndNoStackTrace(): void
    {
        self::bootKernel();
        $html = $this->renderError(500);

        self::assertStringContainsString('data-status="500"', $html);
        self::assertStringContainsString('Something went wrong', $html);
        self::assertStringNotContainsString('boom-secret-detail', $html);
        self::assertStringNotContainsString('RuntimeException', $html);
    }

    /** A status with no copy of its own falls back to the generic title, which embeds the code. */
    public function testUnmappedStatusFallsBackToGenericCopy(): void
    {
        self::bootKernel();
        $html = $this->renderError(418);

        self::assertStringContainsString('data-status="418"', $html);
        self::assertStringContainsString('418', $html);
    }
}
