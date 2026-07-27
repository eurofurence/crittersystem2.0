<?php

namespace App\Tests\Browser;

use App\Entity\User;
use App\Tests\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Base for tests that drive a real browser.
 *
 * These exist because the rest of the suite renders markup and never executes the JavaScript that
 * drives it. Two defects shipped through that gap: a Stimulus controller that threw before it
 * connected (killing the whole planner), and links inside a Turbo Frame that navigated the frame
 * instead of the page ("Content missing"). Neither is visible to a test that only reads HTML.
 *
 * Panther serves the app in its own process against the same test database this class seeds
 * through the kernel, so fixtures written here are visible to the browser.
 */
abstract class BrowserTestCase extends PantherTestCase
{
    use ResetsDatabase;

    protected EntityManagerInterface $em;
    protected Client $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    /** Starts the browser. Call after the fixtures are flushed, never before. */
    protected function browse(): Client
    {
        return $this->client = static::createPantherClient([
            'browser' => static::CHROME,
        ]);
    }

    /**
     * Sign in through the real login form. Worth doing for real rather than faking the session: the
     * form's CSRF token is written by JavaScript, so a browser is the only thing that submits it the
     * way a user does.
     */
    protected function signIn(User $user, string $plainPassword): void
    {
        $this->client->request('GET', '/login');
        $this->client->waitFor('form[action="/login"], form input[name="_username"]', 10);

        $this->client->submitForm('Sign in', [
            '_username' => $user->getName(),
            '_password' => $plainPassword,
        ]);
        $this->client->waitForElementToNotContain('body', 'Sign in', 10);
    }

    /**
     * Browser console messages at SEVERE level. An uncaught exception in a controller lands here and
     * nowhere else - it does not fail a request, change the markup, or return a non-200.
     *
     * @return string[]
     */
    protected function severeConsoleErrors(): array
    {
        $messages = [];
        foreach ($this->client->getWebDriver()->manage()->getLog('browser') as $entry) {
            if (($entry['level'] ?? '') === 'SEVERE') {
                $messages[] = (string) ($entry['message'] ?? '');
            }
        }

        return $messages;
    }

    protected function assertNoConsoleErrors(string $context): void
    {
        $errors = $this->severeConsoleErrors();
        self::assertSame([], $errors, \sprintf("The browser reported errors on %s:\n%s", $context, implode("\n", $errors)));
    }
}
