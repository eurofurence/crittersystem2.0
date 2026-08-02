<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Mercure\TopicBuilder;
use App\Mercure\Topics;
use App\Tests\DatabaseTestCase;

/**
 * The authorization boundary of the live transport.
 *
 * Whatever {@see TopicBuilder} returns is signed into the subscriber token and delivered by the hub,
 * so a topic that appears here for the wrong user is a leak that no amount of controller checking
 * can undo. These cases protect the boundary itself: a manager scoped to one department must not be
 * handed another department's topics, and a volunteer must not be handed any.
 *
 * The scoping case in particular guards against reaching for `is_granted('shift:manage')` to build
 * this list. PrivilegeVoter grants unconditionally when it is handed no subject, so that
 * implementation would give every manager every department, while looking entirely reasonable.
 */
final class MercureTopicBuilderTest extends DatabaseTestCase
{
    private function builder(): TopicBuilder
    {
        return static::getContainer()->get(TopicBuilder::class);
    }

    private function group(string $slug, ?string $role, string ...$privileges): Group
    {
        $group = new Group(ucfirst($slug), $slug.'-'.bin2hex(random_bytes(2)), $role);
        foreach ($privileges as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        return $group;
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function department(string $name): Department
    {
        $department = new Department($name, strtolower($name).'-'.bin2hex(random_bytes(2)));
        $this->em->persist($department);

        return $department;
    }

    /** A shift manager scoped to one department receives that department's topic and no other. */
    public function testDepartmentScopeIsNotWidenedByAnUnsubjectedPrivilegeCheck(): void
    {
        $alpha = $this->department('Alpha');
        $bravo = $this->department('Bravo');
        $managers = $this->group('shift-manager', 'ROLE_STAFF', 'shift:manage');

        $user = $this->user('alphamanager');
        $user->assignGroup($managers, $alpha);
        $this->em->flush();

        $topics = $this->builder()->forUser($user);

        self::assertContains(Topics::departmentShifts($alpha), $topics);
        self::assertNotContains(
            Topics::departmentShifts($bravo),
            $topics,
            'a manager scoped to Alpha must not be able to subscribe to Bravo',
        );
    }

    /** Department membership alone, with no management privilege, still carries the topic. */
    public function testMembershipAloneGrantsTheDepartmentTopic(): void
    {
        $alpha = $this->department('Alpha');
        $bravo = $this->department('Bravo');
        $staff = $this->group('department-staff', 'ROLE_STAFF', 'shift:view');

        $user = $this->user('alphastaff');
        $user->assignGroup($staff, $alpha);
        $this->em->flush();

        $topics = $this->builder()->forUser($user);

        self::assertContains(Topics::departmentShifts($alpha), $topics);
        self::assertNotContains(Topics::departmentShifts($bravo), $topics);
    }

    /**
     * An unscoped grant is deliberately event-wide, and is expressed as one templated selector.
     *
     * Treating "unscoped" as "no departments" would silently mute these users. Enumerating every
     * department authorizes exactly the same set but is unbounded, and at 62 departments the token
     * outgrew both the browser cookie limit and nginx's header buffer, so every page 502'd. The
     * template also covers departments created after the token was minted, which the enumeration
     * did not.
     */
    public function testAnUnscopedGrantIsExpressedAsOneTemplatedSelector(): void
    {
        $alpha = $this->department('Alpha');
        $this->department('Bravo');
        $managers = $this->group('shift-manager', 'ROLE_STAFF', 'shift:manage');

        $user = $this->user('globalmanager');
        $user->addGroup($managers);
        $this->em->flush();

        $topics = $this->builder()->forUser($user);

        self::assertContains(Topics::allDepartmentShifts(), $topics);
        self::assertNotContains(
            Topics::departmentShifts($alpha),
            $topics,
            'the template already covers it; listing departments as well is what grew the token',
        );
    }

    /** A plain volunteer gets their own topics and nothing departmental or staff-facing. */
    public function testVolunteerGetsOnlyTheirOwnTopics(): void
    {
        $alpha = $this->department('Alpha');
        $volunteers = $this->group('volunteer', null, 'shift:view');

        $user = $this->user('volunteer');
        $user->addGroup($volunteers);
        $this->em->flush();

        $topics = $this->builder()->forUser($user);

        self::assertContains(Topics::userNotifications($user), $topics);
        self::assertContains(Topics::userStatus($user), $topics);
        self::assertNotContains(Topics::departmentShifts($alpha), $topics);
        self::assertNotContains(Topics::infoDeskQueue(), $topics);
    }

    /** Watching the queue is the same right that lets a responder claim a support thread. */
    public function testInfoDeskQueueRequiresTheClaimPrivilege(): void
    {
        $withClaim = $this->user('responder');
        $withClaim->addGroup($this->group('info-desk', 'ROLE_STAFF', 'chat:claim'));

        $without = $this->user('bystander');
        $without->addGroup($this->group('volunteer', null, 'message:use'));
        $this->em->flush();

        self::assertContains(Topics::infoDeskQueue(), $this->builder()->forUser($withClaim));
        self::assertNotContains(Topics::infoDeskQueue(), $this->builder()->forUser($without));
    }

    /** One user's topics never name another user. */
    public function testTopicsAreNotSharedBetweenUsers(): void
    {
        $me = $this->user('me');
        $other = $this->user('other');
        $this->em->flush();

        $topics = $this->builder()->forUser($me);

        self::assertNotContains(Topics::userNotifications($other), $topics);
        self::assertNotContains(Topics::userStatus($other), $topics);
    }

    /** Topics carry public UUIDs; a sequential id here would leak counts and ordering. */
    public function testTopicsCarryNoInternalIds(): void
    {
        $user = $this->user('uuidcheck');
        $department = $this->department('Alpha');
        $user->assignGroup($this->group('department-staff', 'ROLE_STAFF', 'shift:view'), $department);
        $this->em->flush();

        foreach ($this->builder()->forUser($user) as $topic) {
            self::assertDoesNotMatchRegularExpression(
                '/:\d+(:|$)/',
                $topic,
                'topic '.$topic.' looks like it carries a database id',
            );
        }
    }
}
