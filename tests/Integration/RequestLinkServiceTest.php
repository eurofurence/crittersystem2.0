<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\SsoGroupMapping;
use App\Entity\User;
use App\Enum\RequestLinkType;
use App\Repository\UserGroupAssignmentRepository;
use App\Service\EventConfigStore;
use App\Service\Invite\RequestLinkService;
use App\Tests\DatabaseTestCase;

/**
 * Availability/invitation links: links are department-bound and
 * revocable; using one auto-joins a non-SSO department only when the global
 * toggle is on, and never touches SSO-managed membership.
 */
final class RequestLinkServiceTest extends DatabaseTestCase
{
    private function service(): RequestLinkService
    {
        return static::getContainer()->get(RequestLinkService::class);
    }

    private function config(): EventConfigStore
    {
        return static::getContainer()->get(EventConfigStore::class);
    }

    private function dept(string $slug): Department
    {
        $d = new Department('Dept '.$slug, $slug.'-'.bin2hex(random_bytes(2)));
        $this->em->persist($d);

        return $d;
    }

    private function user(): User
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    /** Auto-join looks the baseline group up by the exact `volunteer` slug. */
    private function volunteerGroup(): void
    {
        $this->em->persist(new Group('Volunteer', 'volunteer'));
    }

    public function testLinkIsDepartmentBoundAndRevocable(): void
    {
        $dept = $this->dept('logistics');
        $this->em->flush();

        $link = $this->service()->create(RequestLinkType::AVAILABILITY_REQUEST, $dept, null);
        self::assertSame($dept->getId(), $link->getDepartment()->getId());
        self::assertTrue($link->isActive());
        self::assertNotNull($this->service()->findActiveByToken($link->getToken()));

        $this->service()->revoke($link);
        self::assertNull($this->service()->findActiveByToken($link->getToken()), 'revoked link is inactive');
    }

    public function testExpiredLinkIsInactive(): void
    {
        $dept = $this->dept('sound');
        $this->em->flush();
        $link = $this->service()->create(RequestLinkType::SHIFT_INVITATION, $dept, null, new \DateTimeImmutable('-1 hour'));

        self::assertNull($this->service()->findActiveByToken($link->getToken()));
    }

    public function testAutoMembershipOffDoesNotJoin(): void
    {
        $this->config()->set(EventConfigStore::KEY_MEMBERSHIP_AUTO_FROM_LINKS, false);
        $this->volunteerGroup();
        $dept = $this->dept('bar');
        $user = $this->user();
        $this->em->flush();
        $link = $this->service()->create(RequestLinkType::SHIFT_INVITATION, $dept, null);

        self::assertFalse($this->service()->use($link, $user));
        self::assertFalse(static::getContainer()->get(UserGroupAssignmentRepository::class)->userIsMember($user, $dept));
    }

    public function testAutoMembershipOnJoinsNonSsoDepartment(): void
    {
        $this->config()->set(EventConfigStore::KEY_MEMBERSHIP_AUTO_FROM_LINKS, true);
        $this->volunteerGroup();
        $dept = $this->dept('gate');
        $user = $this->user();
        $this->em->flush();
        $link = $this->service()->create(RequestLinkType::SHIFT_INVITATION, $dept, null);

        self::assertTrue($this->service()->use($link, $user));
        self::assertTrue(static::getContainer()->get(UserGroupAssignmentRepository::class)->userIsMember($user, $dept));
    }

    /** A department carrying an SSO group mapping is SSO-managed, and a link never changes its membership. */
    public function testSsoManagedDepartmentNeverJoined(): void
    {
        $this->config()->set(EventConfigStore::KEY_MEMBERSHIP_AUTO_FROM_LINKS, true);
        $this->volunteerGroup();
        $dept = $this->dept('tech');
        $mapping = new SsoGroupMapping('sso-tech');
        $mapping->setDepartment($dept);
        $this->em->persist($mapping);
        $user = $this->user();
        $this->em->flush();
        $link = $this->service()->create(RequestLinkType::SHIFT_INVITATION, $dept, null);

        self::assertTrue($this->service()->isSsoManaged($dept));
        self::assertFalse($this->service()->use($link, $user), 'SSO membership is never changed by links');
        self::assertFalse(static::getContainer()->get(UserGroupAssignmentRepository::class)->userIsMember($user, $dept));
    }
}
