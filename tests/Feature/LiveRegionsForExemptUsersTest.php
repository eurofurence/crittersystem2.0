<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Mercure\SubscriberCookieFactory;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Live regions must follow the onboarding gate's own rule, not a copy of half of it.
 *
 * The gate exempts administrators: they are never redirected to the wizard, so their fragment
 * requests are never refused. Gating the regions on "has completed onboarding" alone therefore hid
 * the navbar bell and the status widget from every administrator, and - because the hub URL was
 * gated the same way - stopped their browser connecting at all, which silently took chat, the
 * planner and every other live surface with it. The site administrator is exactly the account most
 * likely never to have walked through the wizard.
 */
final class LiveRegionsForExemptUsersTest extends DatabaseWebTestCase
{
    private function user(string $name, ?string $role, bool $onboarded): User
    {
        $group = new Group(ucfirst($name), $name.'-'.bin2hex(random_bytes(2)), $role);
        foreach (['news:view', 'shift:view', 'shift:self'] as $privilege) {
            $entity = new Privilege($privilege);
            $this->em->persist($entity);
            $group->addPrivilege($entity);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        if ($onboarded) {
            $user->completeOnboarding();
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function tokenIssued(): bool
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === SubscriberCookieFactory::COOKIE_NAME) {
                return true;
            }
        }

        return false;
    }

    /** An administrator who never used the wizard still gets the navbar widgets and a token. */
    public function testAnAdminWhoNeverOnboardedStillGetsLiveRegions(): void
    {
        $this->client->loginUser($this->user('siteadmin', 'ROLE_ADMIN', onboarded: false));
        $crawler = $this->client->request('GET', '/news');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#notifications-bell'), 'the notification bell must be rendered');
        self::assertCount(1, $crawler->filter('#operational-status'));
        self::assertTrue($this->tokenIssued(), 'without a token the browser receives nothing at all');
    }

    /** An ordinary onboarded user is unaffected. */
    public function testAnOnboardedUserGetsLiveRegions(): void
    {
        $this->client->loginUser($this->user('volunteer', null, onboarded: true));
        $crawler = $this->client->request('GET', '/news');

        self::assertCount(1, $crawler->filter('#notifications-bell'));
        self::assertTrue($this->tokenIssued());
    }

    /**
     * A user the gate really does redirect gets neither, which is the case the gating was for: the
     * fragments those regions fetch are refused, so a region would only ask and be turned away.
     */
    public function testAUserStillInTheWizardGetsNoLiveRegions(): void
    {
        $this->client->loginUser($this->user('newcomer', null, onboarded: false));
        $crawler = $this->client->request('GET', '/onboarding');

        self::assertCount(0, $crawler->filter('#notifications-bell'));
        self::assertFalse($this->tokenIssued());
    }
}
