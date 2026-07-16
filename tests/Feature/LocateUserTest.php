<?php

namespace App\Tests\Feature;

use App\Entity\DigitalIdToken;
use App\Entity\Group;
use App\Entity\PersonalData;
use App\Entity\Privilege;
use App\Entity\State;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * The info-desk "Locate user" tool: who may reach it, how the hardened lookup resolves, and that a
 * volunteer's email is not exposed as an identity field to non-admin staff.
 */
final class LocateUserTest extends DatabaseWebTestCase
{
    private function user(string $name, ?string $role, string ...$privileges): User
    {
        $group = new Group(ucfirst($name), $name.'-'.bin2hex(random_bytes(2)), $role);
        foreach ($privileges as $privilege) {
            $p = new Privilege($privilege);
            $this->em->persist($p);
            $group->addPrivilege($p);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function target(string $name, ?int $badge = null): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $pd = new PersonalData($user);
        $pd->setBadgeNumber($badge);
        $user->setPersonalData($pd);
        $this->em->persist($user);
        $this->em->persist($pd);
        $this->em->flush();

        return $user;
    }

    private function locator(): User
    {
        return $this->user('desk', 'ROLE_STAFF', 'user:locate', 'user:arrive', 'goodie:view', 'goodie:distribute', 'shift:view');
    }

    public function testWithoutTheLocatePrivilegeAccessIsDenied(): void
    {
        $this->client->loginUser($this->user('plain', null, 'news:view'));
        $this->client->request('GET', '/backstage/distribute');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExactEmailRedirectsStraightToTheUser(): void
    {
        $this->client->loginUser($this->locator());
        $target = $this->target('scan-me');

        $this->client->request('GET', '/backstage/distribute?q=scan-me@example.com');

        self::assertResponseRedirects('/backstage/distribute/'.$target->getUuid());
    }

    public function testPartialEmailDoesNotResolve(): void
    {
        $this->client->loginUser($this->locator());
        $this->target('scan-me');

        $this->client->request('GET', '/backstage/distribute?q=scan-me@exa');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No matches');
    }

    public function testScannedBadgeResolvesToTheUser(): void
    {
        $this->client->loginUser($this->locator());
        $target = $this->target('badged');
        $token = new DigitalIdToken($target);
        $this->em->persist($token);
        $this->em->flush();

        // The scanner pastes the whole verify URL; the token is extracted from it.
        $this->client->request('GET', '/backstage/distribute?q='.urlencode('https://host/digital-id/verify/'.$token->getToken()));

        self::assertResponseRedirects('/backstage/distribute/'.$target->getUuid());
    }

    public function testEmailIsRedactedForNonAdminsButNotAdmins(): void
    {
        $this->target('target-one');
        $this->target('target-two');

        // Non-admin staff: the email column is redacted, and the raw address is absent from the page.
        $this->client->loginUser($this->locator());
        $this->client->request('GET', '/backstage/distribute?q=target');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('target-one@example.com', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('td.small', '—');

        // Admin: the address is shown.
        $this->client->loginUser($this->user('boss', 'ROLE_ADMIN', 'global:admin'));
        $this->client->request('GET', '/backstage/distribute?q=target');
        self::assertStringContainsString('target-one@example.com', (string) $this->client->getResponse()->getContent());
    }

    public function testRegistrationNumberIsShownOnTheUserPage(): void
    {
        $this->client->loginUser($this->locator());
        $target = $this->target('registered', 987654);

        $this->client->request('GET', '/backstage/distribute/'.$target->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '987654');
    }

    public function testCheckInAndRemoval(): void
    {
        $this->client->loginUser($this->locator());
        $target = $this->target('arriving');

        $crawler = $this->client->request('GET', '/backstage/distribute/'.$target->getUuid());
        $this->client->submit($crawler->selectButton('Check in')->form());
        self::assertResponseRedirects('/backstage/distribute/'.$target->getUuid());

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        self::assertTrue($reloaded->getState()->isArrived());
        self::assertNotNull($reloaded->getState()->getArrivalDate());

        // And it can be undone.
        $crawler = $this->client->request('GET', '/backstage/distribute/'.$target->getUuid());
        $this->client->submit($crawler->selectButton('Remove check-in')->form());

        $this->em->clear();
        $again = $this->em->getRepository(User::class)->find($target->getId());
        self::assertFalse($again->getState()->isArrived());
        self::assertNull($again->getState()->getArrivalDate());
    }
}
