<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Mercure\SubscriberCookieFactory;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * How the subscriber token reaches the browser.
 *
 * The token is the only thing that decides what the hub will deliver, so how it travels matters as
 * much as what it says. Scoped to the hub path it is never attached to ordinary application
 * requests; HttpOnly it cannot be read back and replayed by injected script; Strict it never rides
 * a cross-site request.
 */
final class MercureCookieDeliveryTest extends DatabaseWebTestCase
{
    private function onboardedUser(): User
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
        $user->setName('subscriber')->setEmail('subscriber@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function tokenCookie(): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === SubscriberCookieFactory::COOKIE_NAME) {
                return $cookie;
            }
        }

        return null;
    }

    public function testASignedInPageCarriesAHardenedToken(): void
    {
        $this->client->loginUser($this->onboardedUser());
        $this->client->request('GET', '/news');

        $cookie = $this->tokenCookie();

        self::assertNotNull($cookie, 'a signed-in page must carry a subscriber token');
        self::assertSame(SubscriberCookieFactory::COOKIE_PATH, $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertNotSame('', (string) $cookie->getValue());
    }

    /** The heartbeat exists to hand over a fresh one before the old one lapses. */
    public function testTheHeartbeatReissuesTheToken(): void
    {
        $this->client->loginUser($this->onboardedUser());
        $this->client->request('GET', '/session/heartbeat', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->tokenCookie());
    }

    /** An anonymous page must not be handed one at all. */
    public function testAnonymousPagesCarryNoToken(): void
    {
        $this->client->request('GET', '/login');

        self::assertNull($this->tokenCookie());
    }
}
