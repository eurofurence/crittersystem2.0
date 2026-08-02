<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\OperationalStatusOverride;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Mercure\Topics;
use App\Mercure\UpdatePublisher;
use App\Service\Call\HelpCallService;
use App\Service\OperationalStatusService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\RecordedUpdates;

/**
 * Who a help call wakes.
 *
 * A call is offered per user: eligibility depends on their volunteer types, their operational
 * status, the shift's audience and what else they are already doing. There is therefore no shared
 * "a call happened" topic to publish to - that would tell every volunteer in the event that
 * somewhere, someone needs help, which is not theirs to know. Each affected user is signalled on
 * their own topic instead, and the board they then fetch is filtered server-side as always.
 */
final class LiveHelpCallSignalTest extends DatabaseTestCase
{
    private VolunteerType $type;
    private Shift $shift;
    private Group $staffGroup;

    private function calls(): HelpCallService
    {
        return static::getContainer()->get(HelpCallService::class);
    }

    private function statusService(): OperationalStatusService
    {
        return static::getContainer()->get(OperationalStatusService::class);
    }

    private function flush(): void
    {
        static::getContainer()->get(UpdatePublisher::class)->flush();
    }

    protected function setUp(): void
    {
        parent::setUp();
        RecordedUpdates::clear();

        $dept = new Department('Ops '.bin2hex(random_bytes(3)), 'ops-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($this->type);
        $this->staffGroup = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        $this->em->persist($this->staffGroup);

        $this->shift = (new Shift())->setTitle('Gate duty at the north entrance')
            ->setStartsAt(new \DateTimeImmutable('+1 hour'))
            ->setEndsAt(new \DateTimeImmutable('+3 hours'))
            ->setDepartment($dept);
        $this->em->persist($this->shift);
        $need = new NeededVolunteerType($this->type, 2);
        $this->shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();
    }

    private function member(string $name, bool $freeToHelp = true, bool $qualified = true): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($this->staffGroup);
        $this->em->persist($user);

        if ($qualified) {
            $membership = new UserVolunteerType($user, $this->type);
            $membership->setConfirmedBy($user);
            $this->em->persist($membership);
        }
        if ($freeToHelp) {
            $this->em->persist(new OperationalStatusOverride(
                $user,
                OperationalStatusService::FREE_TO_HELP,
                new \DateTimeImmutable('+2 hours'),
            ));
        }
        $this->em->flush();

        return $user;
    }

    public function testTriggeringWakesOnlyThoseWhoCouldAnswer(): void
    {
        $eligible = $this->member('eligible');
        $busy = $this->member('busy', freeToHelp: false);
        $unqualified = $this->member('unqualified', qualified: false);

        $this->calls()->trigger($this->shift, null, 1);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::userCalls($eligible)));
        self::assertCount(
            0,
            RecordedUpdates::forTopic(Topics::userCalls($busy)),
            'someone who is not free to help cannot answer and must not be woken',
        );
        self::assertCount(
            0,
            RecordedUpdates::forTopic(Topics::userCalls($unqualified)),
            'someone without the volunteer type cannot answer and must not be woken',
        );
    }

    /** The hub learns that a board changed, never which shift or why. */
    public function testTheSignalSaysNothingAboutTheCall(): void
    {
        $eligible = $this->member('eligible');

        $this->calls()->trigger($this->shift, null, 1);
        $this->flush();

        $data = RecordedUpdates::forTopic(Topics::userCalls($eligible))[0]->getData();

        self::assertStringNotContainsString('Gate duty', $data);
        self::assertStringNotContainsString('north entrance', $data);
        self::assertStringContainsString('"signal":true', $data);
    }

    /**
     * Accepting has to reach the people who can no longer answer.
     *
     * They are precisely the ones a set computed after the change would miss, which is why the
     * eligible set is taken on both sides of it.
     */
    public function testAcceptingWakesTheOthersWhoseBoardChanged(): void
    {
        $first = $this->member('first');
        $second = $this->member('second');

        $call = $this->calls()->trigger($this->shift, null, 1);
        $this->flush();
        RecordedUpdates::clear();

        $this->calls()->accept($call, $first);
        $this->flush();

        self::assertNotEmpty(
            RecordedUpdates::forTopic(Topics::userCalls($second)),
            'the call is now full and must come off the other board',
        );
        self::assertNotEmpty(RecordedUpdates::forTopic(Topics::userCalls($first)));
        self::assertNotEmpty(
            RecordedUpdates::forTopic(Topics::userStatus($first)),
            'the accepter now holds an assignment, which their operational status is derived from',
        );
    }

    /** Refusing is one person's decision and nobody else's business. */
    public function testRefusingWakesOnlyTheRefuser(): void
    {
        $refuser = $this->member('refuser');
        $other = $this->member('other');

        $call = $this->calls()->trigger($this->shift, null, 2);
        $this->flush();
        RecordedUpdates::clear();

        $this->calls()->refuse($call, $refuser);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::userCalls($refuser)));
        self::assertCount(0, RecordedUpdates::forTopic(Topics::userCalls($other)));
    }

    /**
     * Becoming free to help is what makes an already-open call answerable.
     *
     * Nothing happens to the call at that moment, so without this the board would stay empty until
     * something unrelated refreshed it - which for a call that is being answered right now means
     * never.
     */
    public function testBecomingFreeToHelpWakesTheOwnBoard(): void
    {
        $latecomer = $this->member('latecomer', freeToHelp: false);

        $this->calls()->trigger($this->shift, null, 1);
        $this->flush();
        RecordedUpdates::clear();

        $this->statusService()->setFreeToHelp($latecomer, 30);
        $this->flush();

        self::assertNotEmpty(RecordedUpdates::forTopic(Topics::userCalls($latecomer)));
        self::assertNotEmpty(RecordedUpdates::forTopic(Topics::userStatus($latecomer)));
    }

    /** Cancelling takes the call off every board that was showing it. */
    public function testCancellingWakesEveryoneWhoCouldSeeIt(): void
    {
        $eligible = $this->member('eligible');

        $call = $this->calls()->trigger($this->shift, null, 1);
        $this->flush();
        RecordedUpdates::clear();

        $this->calls()->cancel($call);
        $this->flush();

        self::assertNotEmpty(RecordedUpdates::forTopic(Topics::userCalls($eligible)));
    }

    /** Every update is private, or the hub delivers it to everyone connected. */
    public function testCallSignalsArePrivate(): void
    {
        $this->member('eligible');

        $this->calls()->trigger($this->shift, null, 1);
        $this->flush();

        self::assertNotEmpty(RecordedUpdates::all());
        foreach (RecordedUpdates::all() as $update) {
            self::assertTrue($update->isPrivate());
        }
    }
}
