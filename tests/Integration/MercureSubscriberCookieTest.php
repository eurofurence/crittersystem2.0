<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Mercure\SubscriberCookieFactory;
use App\Mercure\Topics;
use App\Tests\DatabaseTestCase;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The token a browser presents to the hub.
 *
 * It is the only thing standing between a user and every other user's live updates, so its claims
 * and its cookie flags are asserted rather than assumed: a token that quietly carried a publish
 * claim, never expired, or travelled cross-site would each turn the transport into a leak.
 */
final class MercureSubscriberCookieTest extends DatabaseTestCase
{
    private function factory(): SubscriberCookieFactory
    {
        return static::getContainer()->get(SubscriberCookieFactory::class);
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function claims(Cookie $cookie): array
    {
        $token = (new Parser(new JoseEncoder()))->parse((string) $cookie->getValue());
        \assert($token instanceof \Lcobucci\JWT\UnencryptedToken);

        return $token->claims()->all();
    }

    public function testTokenNamesExactlyTheUsersTopicsAndNothingElse(): void
    {
        $alpha = new Department('Alpha', 'alpha-'.bin2hex(random_bytes(2)));
        $bravo = new Department('Bravo', 'bravo-'.bin2hex(random_bytes(2)));
        $this->em->persist($alpha);
        $this->em->persist($bravo);

        $group = new Group('Shift manager', 'sm-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        $privilege = new Privilege('shift:manage');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = $this->user('scoped');
        $user->assignGroup($group, $alpha);
        $this->em->flush();

        $claims = $this->claims($this->factory()->create($user, true));

        $subscribe = $claims['mercure']->subscribe ?? $claims['mercure']['subscribe'];
        self::assertContains(Topics::departmentShifts($alpha), $subscribe);
        self::assertNotContains(Topics::departmentShifts($bravo), $subscribe);
        self::assertContains(Topics::userNotifications($user), $subscribe);
    }

    /** A subscriber token must never be able to write to the hub. */
    public function testTokenCarriesNoPublishClaim(): void
    {
        $user = $this->user('reader');
        $this->em->flush();

        $mercure = $this->claims($this->factory()->create($user, true))['mercure'];
        $mercure = (array) $mercure;

        self::assertArrayHasKey('subscribe', $mercure);
        self::assertArrayNotHasKey('publish', $mercure, 'a browser token must not be able to publish');
    }

    /** Five minutes is the revocation window; a longer-lived token widens it silently. */
    public function testTokenExpiresInFiveMinutes(): void
    {
        $user = $this->user('expiring');
        $this->em->flush();

        $claims = $this->claims($this->factory()->create($user, true));
        $ttl = $claims['exp']->getTimestamp() - $claims['iat']->getTimestamp();

        self::assertSame(SubscriberCookieFactory::TTL_SECONDS, $ttl);
        self::assertSame(300, $ttl);
    }

    /**
     * Cookie flags. Scoped to the hub path so it is not attached to ordinary application requests,
     * HttpOnly so a script cannot read it, and Strict so it never rides a cross-site request.
     */
    public function testCookieFlags(): void
    {
        $user = $this->user('flags');
        $this->em->flush();

        $cookie = $this->factory()->create($user, true);

        self::assertSame(SubscriberCookieFactory::COOKIE_PATH, $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
    }

    /**
     * Over plain HTTP the Secure flag would stop the cookie being sent at all, so the live transport
     * would silently never work in local development.
     */
    public function testCookieIsNotSecureOverPlainHttp(): void
    {
        $user = $this->user('insecure');
        $this->em->flush();

        self::assertFalse($this->factory()->create($user, false)->isSecure());
    }

    /** A scoped user's token is never templated: that would authorize every present and future match. */
    public function testAScopedUsersTopicsAreNeverTemplated(): void
    {
        $alpha = new Department('Alpha', 'alpha-'.bin2hex(random_bytes(2)));
        $this->em->persist($alpha);

        $group = new Group('Shift manager', 'sm-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        $privilege = new Privilege('shift:manage');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = $this->user('templates');
        $user->assignGroup($group, $alpha);
        $this->em->flush();

        $mercure = (array) $this->claims($this->factory()->create($user, true))['mercure'];
        foreach ($mercure['subscribe'] as $topic) {
            self::assertStringNotContainsString('{', $topic);
            self::assertStringNotContainsString('*', $topic);
        }
    }

    /**
     * An administrator's token stays small however many departments exist.
     *
     * Enumerating them is not less secure - the grant is event-wide either way - but it is
     * unbounded, and it broke the application outright: 62 departments produced a 6.6 KB token,
     * which is past the browser's cookie limit and past nginx's response-header buffer, so every
     * page an administrator opened returned 502. One templated selector expresses the same
     * entitlement in constant space.
     */
    public function testAnAdministratorsTokenDoesNotGrowWithTheNumberOfDepartments(): void
    {
        for ($i = 0; $i < 40; ++$i) {
            $this->em->persist(new Department('Dept '.$i, 'dept-'.$i.'-'.bin2hex(random_bytes(2))));
        }

        $group = new Group('Admin', 'admin-'.bin2hex(random_bytes(2)), 'ROLE_ADMIN');
        $this->em->persist($group);

        $admin = $this->user('sizecheck');
        $admin->addGroup($group);
        $this->em->flush();

        $cookie = $this->factory()->create($admin, true);
        $subscribe = (array) $this->claims($cookie)['mercure'];
        $subscribe = $subscribe['subscribe'];

        self::assertContains(Topics::allDepartmentShifts(), $subscribe);
        self::assertLessThan(
            10,
            \count($subscribe),
            'an administrator must not carry one topic per department',
        );
        self::assertLessThan(
            2048,
            \strlen((string) $cookie->getValue()),
            'the token must stay well inside the browser cookie limit and the header buffer',
        );
    }
}
