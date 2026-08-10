<?php

namespace App\Tests\Feature;

use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\TogglesSso;

/**
 * The shape of the login page, which changes once an identity provider is connected: SSO leads, and
 * the credential form collapses behind a link.
 *
 * SSO is switched on by setting the environment before the kernel boots, because SsoConfig reads it
 * through `%env(bool:SSO_ENABLED)%` and the test environment ships it off.
 */
final class LoginScreenTest extends DatabaseWebTestCase
{
    use TogglesSso;

    private const PASSWORD_FORM = '#password-login';

    protected function tearDown(): void
    {
        $this->restoreSsoEnv();
        parent::tearDown();
    }

    /** The collapse assertions are scoped to the credential form: the page shell has its own navbar toggle. */
    public function testWithoutSsoThePasswordFormIsTheWholePageAndIsNotCollapsed(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
        self::assertSame(0, $crawler->filter(self::PASSWORD_FORM.'.collapse')->count());
        self::assertSame(0, $crawler->filter('a[data-bs-toggle="collapse"][href="'.self::PASSWORD_FORM.'"]')->count());
    }

    /** With a provider connected the credential form's submit must not compete with it for attention. */
    public function testWithSsoTheProviderButtonComesFirstAndIsTheProminentOne(): void
    {
        $crawler = $this->bootWithSso()->request('GET', '/login');

        self::assertResponseIsSuccessful();

        $ssoButton = $crawler->filter('a[href="/login/sso"]');
        self::assertSame(1, $ssoButton->count());
        self::assertStringContainsString('btn-lg', (string) $ssoButton->attr('class'));
        self::assertStringContainsString('btn-primary', (string) $ssoButton->attr('class'));

        self::assertStringNotContainsString('btn-primary', (string) $crawler->filter(self::PASSWORD_FORM.' button[type="submit"]')->attr('class'));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(
            strpos($html, 'name="_username"'),
            strpos($html, 'href="/login/sso"'),
            'the provider button has to render above the credential fields, not below them',
        );
    }

    public function testWithSsoThePasswordFieldsStartCollapsedBehindALink(): void
    {
        $crawler = $this->bootWithSso()->request('GET', '/login');

        $form = $crawler->filter(self::PASSWORD_FORM);
        self::assertSame(1, $form->count());
        self::assertStringContainsString('collapse', (string) $form->attr('class'));
        self::assertStringNotContainsString('show', (string) $form->attr('class'));
        self::assertSame(1, $crawler->filter('a[data-bs-toggle="collapse"][href="'.self::PASSWORD_FORM.'"]')->count());
    }

    public function testAFailedAttemptLeavesTheFieldsExpanded(): void
    {
        $client = $this->bootWithSso();
        $crawler = $client->request('GET', '/login');

        $client->submit($crawler->filter('form[action$="/login"]')->form([
            '_username' => 'nobody',
            '_password' => 'wrong-password',
        ]));
        $crawler = $client->followRedirect();

        self::assertStringContainsString(
            'show',
            (string) $crawler->filter(self::PASSWORD_FORM)->attr('class'),
            'hiding the fields again would leave the person retrying hunting for them',
        );
    }

    public function testTurningPasswordSignInOffRemovesTheFormEntirely(): void
    {
        $client = $this->bootWithSso();
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED, false);
        $store->flush();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a[href="/login/sso"]')->count());
        self::assertSelectorNotExists('input[name="_password"]');
        self::assertSame(0, $crawler->filter('a[data-bs-toggle="collapse"][href="'.self::PASSWORD_FORM.'"]')->count());
    }

    /**
     * The credential form is hand-built, so it does not get the CSRF wiring the form theme adds.
     *
     * `authenticate` is a stateless CSRF id: the rendered value is the sentinel the browser is meant
     * to swap for a random token that it mirrors into a cookie, and the code doing the swapping is a
     * lazy Stimulus controller that only loads for this attribute. Without it the form posts the
     * sentinel and is accepted on origin alone - and any session that has already submitted a
     * form-theme form is then refused here for good, because SameOriginCsrfTokenManager will not
     * allow a session to fall back from double-submit to origin.
     */
    public function testTheCredentialFormAsksForTheCsrfControllerTheFormThemeWouldHaveAdded(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="_csrf_token"]');
        self::assertSame(1, $token->count());
        self::assertSame(
            'csrf-protection',
            $token->attr('data-controller'),
            'without this the double-submit token is never generated and the sentinel is posted as-is',
        );
    }
}
