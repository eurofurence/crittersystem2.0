<?php

namespace App\Tests\Feature;

use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Painting consecutive whole days.
 *
 * Two full days of the same value are stored as one span, because touching same-value ranges are
 * consolidated on save - correct, and invisible to the user. The grid, however, works in
 * {day, startMin, endMin}, so a span that crosses midnight has to be handed back to it as one entry
 * per day. It was handed back as a single entry clamped to 1440 minutes, so the second day simply
 * vanished on reload and the volunteer's work looked deleted. Leaving a gap - starting the second
 * day at 00:30 - avoided the merge and hid the bug, which is exactly how it was reported.
 */
final class AvailabilityMultiDayTest extends DatabaseWebTestCase
{
    private function login(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('painter')->setEmail('painter@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /** The grid spans the configured event window, so the days painted must fall inside it. */
    private function eventDays(): array
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_EVENT_START, '2026-06-01');
        $config->set(EventConfigStore::KEY_EVENT_END, '2026-06-05');

        return ['2026-06-02', '2026-06-03'];
    }

    /** @return list<array<string, mixed>> the ranges handed to the grid */
    private function renderedRanges(): array
    {
        $crawler = $this->client->request('GET', '/availability');
        self::assertResponseIsSuccessful();

        $json = $crawler->filter('[data-availability-ranges-value]')->attr('data-availability-ranges-value');

        return json_decode((string) $json, true) ?? [];
    }

    private function submit(array $ranges): void
    {
        $crawler = $this->client->request('GET', '/availability');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/availability', [
            '_token' => $token,
            'ranges' => json_encode($ranges),
        ]);
        self::assertResponseRedirects('/availability');
    }

    /**
     * Two consecutive whole days both survive a reload. The ranges are exactly what the grid
     * posts for a full day: each one ends at the next day's midnight, so the two abut.
     */
    public function testTwoConsecutiveWholeDaysBothComeBack(): void
    {
        $this->login();
        [$first, $second] = $this->eventDays();

        $this->submit([
            ['start' => $first.'T00:00:00', 'end' => $second.'T00:00:00', 'value' => 'unavailable'],
            ['start' => $second.'T00:00:00', 'end' => '2026-06-04T00:00:00', 'value' => 'unavailable'],
        ]);

        $days = array_column($this->renderedRanges(), 'day');

        self::assertContains($first, $days);
        self::assertContains($second, $days, 'the second whole day must not disappear on reload');
    }

    /** Both days come back full, not merely present. */
    public function testEachWholeDayComesBackAsAWholeDay(): void
    {
        $this->login();
        [$first, $second] = $this->eventDays();

        $this->submit([
            ['start' => $first.'T00:00:00', 'end' => $second.'T00:00:00', 'value' => 'unavailable'],
            ['start' => $second.'T00:00:00', 'end' => '2026-06-04T00:00:00', 'value' => 'unavailable'],
        ]);

        $byDay = [];
        foreach ($this->renderedRanges() as $range) {
            $byDay[$range['day']] = $range;
        }

        foreach ([$first, $second] as $day) {
            self::assertArrayHasKey($day, $byDay);
            self::assertSame(0, $byDay[$day]['startMin'], $day.' must start at midnight');
            self::assertSame(1440, $byDay[$day]['endMin'], $day.' must run to midnight');
            self::assertSame('unavailable', $byDay[$day]['value']);
        }
    }

    /**
     * The gap workaround users found must keep working: it is what they are doing today, and it
     * exercises the non-merged path.
     */
    public function testTwoDaysWithAGapStillComeBackSeparately(): void
    {
        $this->login();
        [$first, $second] = $this->eventDays();

        $this->submit([
            ['start' => $first.'T00:00:00', 'end' => $second.'T00:00:00', 'value' => 'unavailable'],
            ['start' => $second.'T00:30:00', 'end' => '2026-06-04T00:00:00', 'value' => 'unavailable'],
        ]);

        $byDay = [];
        foreach ($this->renderedRanges() as $range) {
            $byDay[$range['day']] = $range;
        }

        self::assertArrayHasKey($first, $byDay);
        self::assertArrayHasKey($second, $byDay);
        self::assertSame(30, $byDay[$second]['startMin']);
    }

    /** A span of several whole days yields one entry per day, not one for the first. */
    public function testAThreeDaySpanYieldsThreeDays(): void
    {
        $this->login();
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_EVENT_START, '2026-06-01');
        $config->set(EventConfigStore::KEY_EVENT_END, '2026-06-08');

        $this->submit([
            ['start' => '2026-06-02T00:00:00', 'end' => '2026-06-05T00:00:00', 'value' => 'available'],
        ]);

        $days = array_column($this->renderedRanges(), 'day');

        self::assertContains('2026-06-02', $days);
        self::assertContains('2026-06-03', $days);
        self::assertContains('2026-06-04', $days);
    }

    /** A partial span that crosses midnight splits at the boundary rather than being clamped. */
    public function testAnOvernightSpanSplitsAtMidnight(): void
    {
        $this->login();
        [$first, $second] = $this->eventDays();

        $this->submit([
            ['start' => $first.'T22:00:00', 'end' => $second.'T06:00:00', 'value' => 'preferred'],
        ]);

        $byDay = [];
        foreach ($this->renderedRanges() as $range) {
            $byDay[$range['day']] = $range;
        }

        self::assertSame(1320, $byDay[$first]['startMin'] ?? null);
        self::assertSame(1440, $byDay[$first]['endMin'] ?? null);
        self::assertSame(0, $byDay[$second]['startMin'] ?? null);
        self::assertSame(360, $byDay[$second]['endMin'] ?? null);
    }
}
