<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The board renders from its own standalone layout instead of base.html.twig, so everything the
 * live stack needs is reproduced there by hand.
 *
 * Losing any of those pieces produces a board that still renders perfectly and silently stops
 * updating, which is the one failure a wall display cannot show. These assertions exist so that
 * cannot happen quietly:
 *
 *  - the mercure-hub meta, which assets/js/live.js re-reads on every reconnect;
 *  - the heartbeat-url meta, which re-mints the five-minute subscriber cookie for a board left open
 *    all day;
 *  - the app entrypoint, which carries Turbo, Stimulus and the session-expiry watcher.
 *
 * The first is asserted against the template rather than the response because the test environment
 * deliberately configures no public hub URL, so `mercure_enabled()` is false while the suite runs.
 */
final class BoardLayoutLiveRegionTest extends DatabaseWebTestCase
{
    private const LAYOUT = __DIR__.'/../../templates/board/_layout.html.twig';

    public function testLayoutDeclaresTheHubMetaBehindTheSameGuardsAsTheBaseTemplate(): void
    {
        $layout = file_get_contents(self::LAYOUT);

        self::assertStringContainsString('live_regions_enabled()', $layout);
        self::assertStringContainsString('mercure_enabled()', $layout);
        self::assertStringContainsString('name="mercure-hub"', $layout);
        self::assertStringContainsString('mercure()', $layout);
    }

    public function testRenderedBoardCarriesTheHeartbeatMetaAndTheAppEntrypoint(): void
    {
        $department = new Department('Logistics', 'logistics-'.bin2hex(random_bytes(3)));
        $this->em->persist($department);

        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'board:view']) ?? new Privilege('board:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $department));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/board/'.$department->getUuid().'/'.date('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="heartbeat-url"]');
        self::assertStringContainsString('importmap', (string) $this->client->getResponse()->getContent());
    }

    /** The board is a wall display: none of the application chrome may leak into it. */
    public function testBoardRendersWithoutTheApplicationNavigation(): void
    {
        $department = new Department('Logistics', 'logistics-'.bin2hex(random_bytes(3)));
        $this->em->persist($department);

        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'board:view']) ?? new Privilege('board:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $department));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/board/'.$department->getUuid().'/'.date('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('header.navbar');
        self::assertSelectorExists('[data-board-shell]');
    }
}
