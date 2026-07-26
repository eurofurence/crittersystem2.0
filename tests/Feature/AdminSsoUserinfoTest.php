<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * The userinfo diagnostic on /admin/sso re-runs the OIDC flow for the signed-in SSO admin and shows
 * the provider's raw claims once. It is offered only to SSO-managed accounts, and the claims - which
 * carry the admin's own PII - are cleared from the session as soon as they are shown.
 */
final class AdminSsoUserinfoTest extends DatabaseWebTestCase
{
    /** @var array<string, array{env: string|false, server: string|false}> */
    private array $ssoEnvBackup = [];

    protected function setUp(): void
    {
        // isEnabled() needs both flags; manual endpoints keep the status page and the authorization-URL
        // build network-free. Set before the client boots so the env-backed SsoConfig picks them up.
        // These process-global vars are restored in tearDown so a later test that assumes SSO is
        // disabled (e.g. LandingTest) is not left with it enabled.
        foreach ([
            'SSO_ENABLED' => '1',
            'SSO_CLIENT_ID' => 'critter-test',
            'SSO_AUTHORIZATION_URL' => 'https://idp.example.org/authorize',
            'SSO_TOKEN_URL' => 'https://idp.example.org/token',
            'SSO_USERINFO_URL' => 'https://idp.example.org/userinfo',
        ] as $key => $value) {
            $this->ssoEnvBackup[$key] = ['env' => $_ENV[$key] ?? false, 'server' => $_SERVER[$key] ?? false];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->ssoEnvBackup as $key => $original) {
            $_ENV[$key] = $original['env'] === false ? '' : $original['env'];
            $_SERVER[$key] = $original['server'] === false ? '' : $original['server'];
        }
        parent::tearDown();
    }

    private function admin(bool $sso): User
    {
        $group = new Group('Admins', 'admins-'.bin2hex(random_bytes(2)), 'ROLE_ADMIN');
        $privilege = new Privilege('config:sso');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('root')->setEmail('root@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->setTotpSecret('SECRET')->setTwoFactorEnabled(true);
        if ($sso) {
            $user->setAccountSource(User::SOURCE_SSO);
        }
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Logs the user in, clears step-up, and applies extra session entries in one saved session. */
    private function loginSteppedUp(User $user, array $session = []): void
    {
        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');
        $s = $this->client->getRequest()->getSession();
        $s->set('_mfa_verified_at', time());
        foreach ($session as $key => $value) {
            $s->set($key, $value);
        }
        $s->save();
    }

    public function testClaimsRenderOnceThenClear(): void
    {
        $this->loginSteppedUp($this->admin(sso: true), [
            'sso_userinfo_result' => ['sub' => 'abc-123', 'email' => 'root@idp.example.org', 'groups' => ['staff', 'admins']],
        ]);

        $crawler = $this->client->request('GET', '/admin/sso');
        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('abc-123', $text);
        self::assertStringContainsString('root@idp.example.org', $text);
        // A nested claim is shown as JSON, not dropped or flattened away.
        $html = $crawler->html();
        self::assertStringContainsString('"staff"', $html);
        self::assertStringContainsString('"admins"', $html);

        // Pull-and-clear: reloading the page no longer carries the PII, but the button remains.
        $crawler = $this->client->request('GET', '/admin/sso');
        self::assertStringNotContainsString('abc-123', $crawler->text());
        self::assertStringContainsString('Fetch my userinfo', $crawler->text());
    }

    public function testDiagnosticIsHiddenFromNonSsoAdmins(): void
    {
        $this->loginSteppedUp($this->admin(sso: false));

        $crawler = $this->client->request('GET', '/admin/sso');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Fetch my userinfo', $crawler->text());

        $this->client->request('GET', '/admin/sso/userinfo');
        self::assertResponseStatusCodeSame(404);
    }

    public function testProbeRedirectsToProviderAndArmsTheSession(): void
    {
        $this->loginSteppedUp($this->admin(sso: true));

        $this->client->request('GET', '/admin/sso/userinfo');
        self::assertResponseRedirects();
        self::assertStringStartsWith('https://idp.example.org/authorize', $this->client->getResponse()->headers->get('Location'));

        $session = $this->client->getRequest()->getSession();
        self::assertTrue($session->get('sso_userinfo_probe'));
        self::assertNotNull($session->get('sso_state'));
    }
}
