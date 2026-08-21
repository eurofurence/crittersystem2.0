<?php

namespace App\Tests\Integration;

use App\Service\EventConfigStore;
use App\Service\Statistics\Tallies;
use App\Service\Statistics\TallyStore;
use App\Tests\DatabaseTestCase;

/**
 * The hand-counted figures survive a round trip, and anything malformed in the stored JSON is
 * dropped rather than rendered.
 *
 * These values live in a configuration column that outlives releases, so a slug retired from the
 * catalog or a row written by an older form must not reach the dashboard on the day it is
 * presented in front of an audience.
 */
final class StatisticsTallyStoreTest extends DatabaseTestCase
{
    private function store(): TallyStore
    {
        return static::getContainer()->get(TallyStore::class);
    }

    private function config(): EventConfigStore
    {
        return static::getContainer()->get(EventConfigStore::class);
    }

    public function testRoundTrip(): void
    {
        $this->store()->save(new Tallies(
            known: ['coffee' => 400.0, 'pizza' => 85.0],
            custom: [['label' => 'Wristbands issued', 'amount' => 1200.0]],
            hourlyRate: 13.9,
            currency: 'EUR',
        ));

        $loaded = $this->store()->load();

        self::assertSame(400.0, $loaded->get('coffee'));
        self::assertSame(85.0, $loaded->get('pizza'));
        self::assertSame('Wristbands issued', $loaded->custom[0]['label']);
        self::assertSame(1200.0, $loaded->custom[0]['amount']);
        self::assertSame(13.9, $loaded->hourlyRate);
    }

    public function testUnknownSlugsAreDropped(): void
    {
        $this->config()->set(EventConfigStore::KEY_STATS_TALLIES, ['coffee' => 10, 'retired_slug' => 99]);

        $loaded = $this->store()->load();

        self::assertSame(10.0, $loaded->get('coffee'));
        self::assertNull($loaded->get('retired_slug'));
    }

    public function testNegativeAndNonNumericFiguresAreDropped(): void
    {
        $this->config()->set(EventConfigStore::KEY_STATS_TALLIES, ['coffee' => -5, 'pizza' => 'lots']);

        $loaded = $this->store()->load();

        self::assertNull($loaded->get('coffee'));
        self::assertNull($loaded->get('pizza'));
    }

    public function testMalformedCustomRowsAreDropped(): void
    {
        $this->config()->set(EventConfigStore::KEY_STATS_CUSTOM_TALLIES, [
            ['label' => 'Good row', 'amount' => 5],
            ['label' => '   ', 'amount' => 5],
            ['label' => 'No amount'],
            'not an array',
        ]);

        $loaded = $this->store()->load();

        self::assertCount(1, $loaded->custom);
        self::assertSame('Good row', $loaded->custom[0]['label']);
    }

    /** No rate means the money comparison is hidden, so zero and nonsense must not become a rate. */
    public function testRateIsNullUnlessDeliberatelySet(): void
    {
        self::assertNull($this->store()->load()->hourlyRate);

        $this->config()->set(EventConfigStore::KEY_STATS_HOURLY_RATE, 0);
        self::assertNull($this->store()->load()->hourlyRate);
    }

    public function testEmptyStoreReportsItself(): void
    {
        self::assertTrue($this->store()->load()->isEmpty());
    }
}
