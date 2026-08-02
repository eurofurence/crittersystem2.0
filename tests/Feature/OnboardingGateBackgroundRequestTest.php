<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A request the page makes on its own behalf must never be answered with an HTML document.
 *
 * The onboarding gate used to redirect these like any navigation. `fetch` follows redirects, so a
 * background request came back as 200 OK carrying the whole onboarding page, and the live region
 * that had asked for a fragment assigned that document to its own innerHTML - rendering the entire
 * wizard inside the navbar and destroying the layout of the page underneath.
 *
 * The gate now refuses those requests without a body. It answers 403 rather than the 401 the login
 * entry point uses, because the session is perfectly valid and signalling expiry would throw the
 * user out to the login page for no reason.
 */
final class OnboardingGateBackgroundRequestTest extends DatabaseWebTestCase
{
    private function pendingUser(): User
    {
        $group = new Group('Volunteer', 'volunteer-'.bin2hex(random_bytes(2)), null);
        foreach (['news:view', 'shift:view'] as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('newcomer')->setEmail('newcomer@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        // Deliberately NOT completeOnboarding(): this user is still in the wizard.
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testBackgroundRequestIsRefusedWithoutADocument(): void
    {
        $this->client->loginUser($this->pendingUser());

        $this->client->request('GET', '/notifications/bell', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $response = $this->client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsStringIgnoringCase('<html', (string) $response->getContent());
        self::assertSame('', trim((string) $response->getContent()));
    }

    /**
     * A Turbo navigation must still be redirected, not refused.
     *
     * Turbo Drive makes its visits and form submissions with fetch, so they carry Sec-Fetch-Mode
     * other than "navigate" and look like background requests - but they are navigations, and Turbo
     * follows the redirect and renders the wizard. Refusing them left the user on a dead page after
     * signing in, with a console error and nothing else. Only our own fragment fetches, which set
     * X-Requested-With, are refused.
     */
    public function testTurboNavigationIsRedirectedRatherThanRefused(): void
    {
        $this->client->loginUser($this->pendingUser());

        $this->client->request('GET', '/news', [], [], [
            'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        self::assertResponseRedirects('/onboarding');
    }

    /** A real navigation still goes to the wizard, which is the whole point of the gate. */
    public function testNavigationStillRedirectsToTheWizard(): void
    {
        $this->client->loginUser($this->pendingUser());

        $this->client->request('GET', '/news', [], [], ['HTTP_SEC_FETCH_MODE' => 'navigate']);

        self::assertResponseRedirects('/onboarding');
    }

    /** A user still in the wizard is served no Mercure token, so nothing can be pushed to them. */
    public function testNoSubscriberTokenBeforeOnboardingIsComplete(): void
    {
        $this->client->loginUser($this->pendingUser());
        $this->client->request('GET', '/onboarding');

        $names = array_map(
            static fn ($cookie) => $cookie->getName(),
            $this->client->getResponse()->headers->getCookies(),
        );

        self::assertNotContains('mercureAuthorization', $names);
    }
}
