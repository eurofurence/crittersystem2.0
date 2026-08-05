<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\ShiftGroup;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Service\Shift\ShiftGroupSignupService;
use App\Service\ShiftSignupService;
use App\Tests\DatabaseTestCase;

/**
 * Shifts that can only be taken together.
 *
 * Protects the promise the feature makes: applying to one member signs the volunteer up for every
 * member or for none of them, cancelling one cancels all, and a member the volunteer may not see
 * makes the whole group inapplicable without ever naming that member.
 */
final class ShiftGroupSignupTest extends DatabaseTestCase
{
    private Department $department;
    private VolunteerType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = new Department('D '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->department);
        $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($this->type);
        $this->em->flush();
    }

    private function signup(): ShiftSignupService
    {
        return static::getContainer()->get(ShiftSignupService::class);
    }

    private function groupSignup(): ShiftGroupSignupService
    {
        return static::getContainer()->get(ShiftGroupSignupService::class);
    }

    private function user(): User
    {
        $user = new User();
        $user->setName('u'.bin2hex(random_bytes(3)))
            ->setEmail(bin2hex(random_bytes(4)).'@e.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $this->em->persist($user);

        $membership = new UserVolunteerType($user, $this->type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    private function shift(string $title, string $start, string $end, int $capacity = 1, ?VolunteerType $type = null): Shift
    {
        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($this->department);
        $shift->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);

        $need = new NeededVolunteerType($type ?? $this->type, $capacity);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();

        return $shift;
    }

    /** @param Shift[] $shifts */
    private function group(array $shifts, string $name = 'Main Show'): ShiftGroup
    {
        $group = new ShiftGroup($this->department, $name);
        $this->em->persist($group);
        foreach ($shifts as $shift) {
            $group->addShift($shift);
        }
        $this->em->flush();

        return $group;
    }

    private function entryCount(User $user): int
    {
        return $this->em->getRepository(ShiftEntry::class)->count(['user' => $user->getId()]);
    }

    public function testApplyingToOneMemberSignsUpForEveryMember(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $this->signup()->signUp($user, $show, $this->type);

        self::assertSame(2, $this->entryCount($user));
    }

    public function testAFullSiblingRefusesTheWholeApplicationAndLeavesNoEntries(): void
    {
        $blocker = $this->user();
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        // The rehearsal's only slot goes to somebody else.
        $this->em->persist(new ShiftEntry($rehearsal, $this->type, $blocker));
        $this->em->flush();

        try {
            $this->signup()->signUp($user, $show, $this->type);
            self::fail('The application should have been refused.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(0, $this->entryCount($user), 'A refused group application must leave nothing behind.');
    }

    public function testMembersOfTheSameGroupDoNotOverlapEachOther(): void
    {
        $user = $this->user();
        // A briefing that runs inside the first minutes of the shift it belongs to.
        $briefing = $this->shift('Briefing', '2036-06-01 09:00', '2036-06-01 09:30');
        $shift = $this->shift('Main event', '2036-06-01 09:00', '2036-06-01 15:00');
        $this->group([$briefing, $shift]);

        $this->signup()->signUp($user, $shift, $this->type);

        self::assertSame(2, $this->entryCount($user));
    }

    public function testAnOverlapOutsideTheGroupStillRefuses(): void
    {
        $user = $this->user();
        $other = $this->shift('Unrelated', '2036-06-02 10:00', '2036-06-02 11:00');
        $this->em->persist(new ShiftEntry($other, $this->type, $user));
        $this->em->flush();

        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $this->expectException(\RuntimeException::class);
        $this->signup()->signUp($user, $show, $this->type);
    }

    public function testCancellingOneEntryCancelsEveryMember(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $this->signup()->signUp($user, $show, $this->type);
        self::assertSame(2, $this->entryCount($user));

        $entry = $this->em->getRepository(ShiftEntry::class)->findOneBy(['user' => $user->getId(), 'shift' => $rehearsal->getId()]);
        $this->signup()->cancel($entry);

        self::assertSame(0, $this->entryCount($user), 'Cancelling one member of a group cancels all of them.');
    }

    public function testSelfCancellationIsRefusedOnceAnyMemberHasStarted(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2020-06-01 12:00', '2020-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        // Applied while both were still ahead; the rehearsal has since happened.
        $this->em->persist(new ShiftEntry($rehearsal, $this->type, $user));
        $this->em->persist(new ShiftEntry($show, $this->type, $user));
        $this->em->flush();

        $entry = $this->em->getRepository(ShiftEntry::class)->findOneBy(['user' => $user->getId(), 'shift' => $show->getId()]);
        $error = $this->signup()->cancelError($entry, false);

        self::assertNotNull($error, 'A group with a started member cannot be dropped by the volunteer.');
        self::assertStringContainsString('Show rehearsal', $error, 'The refusal names the member that blocks it.');
    }

    public function testAManagerMayStillCancelAStartedGroup(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2020-06-01 12:00', '2020-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);
        $this->em->persist(new ShiftEntry($rehearsal, $this->type, $user));
        $this->em->persist(new ShiftEntry($show, $this->type, $user));
        $this->em->flush();

        $entry = $this->em->getRepository(ShiftEntry::class)->findOneBy(['user' => $user->getId(), 'shift' => $show->getId()]);

        self::assertNull($this->signup()->cancelError($entry, true));
    }

    public function testAnInvisibleMemberMakesTheGroupInapplicableWithoutNamingIt(): void
    {
        $user = $this->user();
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $secret = $this->shift('Secret briefing', '2036-06-01 12:00', '2036-06-01 13:00');
        // Staff-only, and the volunteer is not staff.
        $secret->setAudience(ShiftAudience::ALL_STAFF);
        $this->em->flush();
        $this->group([$secret, $show]);

        $plan = $this->signup()->plan($user, $show, $this->type);

        self::assertNotNull($plan->error);
        self::assertSame([], $plan->members, 'A hidden member means no member list is built at all.');
        self::assertStringNotContainsStringIgnoringCase('Secret briefing', $plan->error);
        self::assertSame([], $this->signup()->signupOptions($show, $user), 'Nothing is offered on a group that cannot be taken.');

        $this->expectException(\RuntimeException::class);
        $this->signup()->signUp($user, $show, $this->type);
    }

    public function testEligibilityStatusReportsFullWhenOnlyTheSiblingIsFull(): void
    {
        $blocker = $this->user();
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00', 5);
        $this->group([$rehearsal, $show]);

        $this->em->persist(new ShiftEntry($rehearsal, $this->type, $blocker));
        $this->em->flush();

        self::assertSame(
            'full',
            $this->signup()->eligibilityStatus($show, $user),
            'A shift whose sibling is full must not advertise itself as available.',
        );
    }

    public function testASiblingRoleIsResolvedWhenItIsUnambiguous(): void
    {
        $user = $this->user();
        $other = new VolunteerType('Steward '.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $membership = new UserVolunteerType($user, $other);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $this->em->flush();

        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00', 1, $other);
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        $this->signup()->signUp($user, $show, $this->type);

        $onRehearsal = $this->em->getRepository(ShiftEntry::class)->findOneBy(['user' => $user->getId(), 'shift' => $rehearsal->getId()]);
        self::assertNotNull($onRehearsal);
        self::assertSame($other->getId(), $onRehearsal->getVolunteerType()->getId(), 'The sibling records the role it actually asks for.');
    }

    public function testAnUngroupedShiftStillBehavesAsASingleShift(): void
    {
        $user = $this->user();
        $shift = $this->shift('Standalone', '2036-06-01 12:00', '2036-06-01 13:00');

        $this->signup()->signUp($user, $shift, $this->type);

        self::assertSame(1, $this->entryCount($user));
        self::assertNotNull($this->signup()->signUpError($user, $shift, $this->type), 'A second application to the same shift is still refused.');
    }

    public function testAGroupOfOneIsTreatedAsUngrouped(): void
    {
        $user = $this->user();
        $shift = $this->shift('Lonely', '2036-06-01 12:00', '2036-06-01 13:00');
        $this->group([$shift]);

        $plan = $this->signup()->plan($user, $shift, $this->type);

        self::assertFalse($plan->isGrouped());
        $this->signup()->signUp($user, $shift, $this->type);
        self::assertSame(1, $this->entryCount($user));
    }

    public function testCapacityConflictUnderTheLockRollsTheWholeGroupBack(): void
    {
        $user = $this->user();
        $rehearsal = $this->shift('Show rehearsal', '2036-06-01 12:00', '2036-06-01 13:00');
        $show = $this->shift('Main event', '2036-06-02 09:00', '2036-06-02 15:00');
        $this->group([$rehearsal, $show]);

        // Already on the rehearsal only: applying to the show adds nothing new there, and the
        // service must not double-book.
        $this->em->persist(new ShiftEntry($rehearsal, $this->type, $user));
        $this->em->flush();

        $created = $this->groupSignup()->signUpGroup($user, $show, $this->type);

        self::assertCount(1, $created, 'Only the missing member is created.');
        self::assertSame(2, $this->entryCount($user));
    }
}
