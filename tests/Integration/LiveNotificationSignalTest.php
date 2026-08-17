<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Mercure\Topics;
use App\Notification\NotificationCategories;
use App\Service\Notification\NotificationService;
use App\Service\OperationalStatusService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\RecordedUpdates;

/**
 * What the bell and the status widget publish, and to whom.
 *
 * A signal is addressed to one user's topic and says only that something changed. Both halves
 * matter: addressed to the wrong topic it wakes the wrong person's browser, and carrying the
 * notification itself it would put a title and a message through the hub, where the five-minute
 * token is the only thing deciding who reads it.
 */
final class LiveNotificationSignalTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordedUpdates::clear();
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function notifications(): NotificationService
    {
        return static::getContainer()->get(NotificationService::class);
    }

    private function statusService(): OperationalStatusService
    {
        return static::getContainer()->get(OperationalStatusService::class);
    }

    private function flush(): void
    {
        static::getContainer()->get(\App\Mercure\UpdatePublisher::class)->flush();
    }

    public function testNotifyingSignalsOnlyTheAddressedUser(): void
    {
        $me = $this->user('recipient');
        $other = $this->user('bystander');
        $this->em->flush();

        $this->notifications()->notify($me, NotificationCategories::GENERAL, 'Shift reminder', 'You are on in an hour.');
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::userNotifications($me)));
        self::assertCount(
            0,
            RecordedUpdates::forTopic(Topics::userNotifications($other)),
            'a notification must not wake another user',
        );
    }

    /** The hub carries the fact of a change, never the change itself. */
    public function testSignalCarriesNeitherTitleNorMessage(): void
    {
        $me = $this->user('recipient');
        $this->em->flush();

        $this->notifications()->notify($me, NotificationCategories::GENERAL, 'Badge revoked', 'Report to the Info Desk.');
        $this->flush();

        $data = RecordedUpdates::forTopic(Topics::userNotifications($me))[0]->getData();

        self::assertStringNotContainsString('Badge revoked', $data);
        self::assertStringNotContainsString('Info Desk', $data);
        self::assertStringContainsString('"signal":true', $data);
    }

    /** Every update is private, or the hub delivers it to everyone connected. */
    public function testSignalIsPrivate(): void
    {
        $me = $this->user('recipient');
        $this->em->flush();

        $this->notifications()->notify($me, NotificationCategories::GENERAL, 'x', 'y');
        $this->flush();

        self::assertTrue(RecordedUpdates::forTopic(Topics::userNotifications($me))[0]->isPrivate());
    }

    /**
     * Marking all read has to drop the count in the user's other open tabs too.
     *
     * Updates are queued until the request ends, so the setup notification is flushed before the
     * recording is cleared; left pending it would arrive later and be counted as the signal.
     */
    public function testMarkAllReadSignals(): void
    {
        $me = $this->user('reader');
        $this->em->flush();

        $this->notifications()->notify($me, NotificationCategories::GENERAL, 'x', 'y');
        $this->flush();
        RecordedUpdates::clear();

        $this->notifications()->markAllRead($me);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::userNotifications($me)));
    }

    public function testSettingAndClearingFreeToHelpSignalsTheStatusTopic(): void
    {
        $me = $this->user('helper');
        $other = $this->user('bystander');
        $this->em->flush();

        $this->statusService()->setFreeToHelp($me, 30);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::userStatus($me)));
        self::assertCount(0, RecordedUpdates::forTopic(Topics::userStatus($other)));

        RecordedUpdates::clear();
        $this->statusService()->clear($me);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::userStatus($me)));
    }

    /**
     * The status changes on the clock, with nothing happening server-side at that instant, so the
     * view model reports when the widget should look again. Without it the "free to help" badge
     * sits there after it has lapsed until something unrelated refreshes the page.
     */
    public function testViewModelReportsWhenAnOverrideWillLapse(): void
    {
        $me = $this->user('helper');
        $this->em->flush();

        $this->statusService()->setFreeToHelp($me, 30);
        $vm = $this->statusService()->viewModel($me);

        self::assertNotNull($vm['nextTransitionAt']);
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable('+30 minutes'))->getTimestamp(),
            $vm['nextTransitionAt']->getTimestamp(),
            60,
        );
    }

    /** Nothing scheduled means nothing to wait for; the region must not invent a timer. */
    public function testNoTransitionWhenNothingIsPending(): void
    {
        $me = $this->user('idle');
        $this->em->flush();

        self::assertNull($this->statusService()->viewModel($me)['nextTransitionAt']);
    }
}
