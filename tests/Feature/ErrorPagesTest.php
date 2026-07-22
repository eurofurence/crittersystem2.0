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
    private function renderError(int $code): string
    {
        // A request context so base.html.twig (routing, locale, theme) resolves.
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

    public function testForbiddenShowsPermissionCopyInsideChrome(): void
    {
        self::bootKernel();
        $html = $this->renderError(403);

        self::assertStringContainsString('data-status="403"', $html);
        self::assertStringContainsString('have permission', $html);
        // Rendered inside the shared site layout, not a bare page.
        self::assertStringContainsString('navbar-brand', $html);
    }

    public function testNotFoundShowsItsOwnCopy(): void
    {
        self::bootKernel();
        $html = $this->renderError(404);

        self::assertStringContainsString('data-status="404"', $html);
        self::assertStringContainsString('Page not found', $html);
    }

    public function testServerErrorShowsFriendlyCopyAndNoStackTrace(): void
    {
        self::bootKernel();
        $html = $this->renderError(500);

        self::assertStringContainsString('data-status="500"', $html);
        self::assertStringContainsString('Something went wrong', $html);
        // The exception details must never reach the user.
        self::assertStringNotContainsString('boom-secret-detail', $html);
        self::assertStringNotContainsString('RuntimeException', $html);
    }

    public function testUnmappedStatusFallsBackToGenericCopy(): void
    {
        self::bootKernel();
        $html = $this->renderError(418);

        self::assertStringContainsString('data-status="418"', $html);
        // The generic title embeds the code.
        self::assertStringContainsString('418', $html);
    }
}
