<?php

namespace App\Tests\Integration;

use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;

final class EventConfigStoreTest extends DatabaseTestCase
{
    private function store(): EventConfigStore
    {
        return static::getContainer()->get(EventConfigStore::class);
    }

    public function testReturnsDefaultForMissingKey(): void
    {
        self::assertSame('fallback', $this->store()->get('does.not.exist', 'fallback'));
        self::assertNull($this->store()->get('does.not.exist'));
    }

    public function testSetGetAndOverwrite(): void
    {
        $store = $this->store();

        $store->set(EventConfigStore::KEY_NAME, 'Eurofurence 28');
        $store->set(EventConfigStore::KEY_ACCESS_MODE, 'staff');
        $store->flush();

        self::assertSame('Eurofurence 28', $store->get(EventConfigStore::KEY_NAME));
        self::assertSame('staff', $store->get(EventConfigStore::KEY_ACCESS_MODE));

        // Overwriting an existing key updates in place (no duplicate row).
        $store->set(EventConfigStore::KEY_NAME, 'Eurofurence 29');
        $store->flush();

        self::assertSame('Eurofurence 29', $store->get(EventConfigStore::KEY_NAME));

        $all = $store->all();
        self::assertSame('Eurofurence 29', $all[EventConfigStore::KEY_NAME]);
        self::assertCount(2, $all);
    }

    public function testStoresStructuredJsonValues(): void
    {
        $store = $this->store();

        $store->set('event.flags', ['a' => true, 'b' => 2]);
        $store->flush();

        self::assertSame(['a' => true, 'b' => 2], $store->get('event.flags'));
    }

    /**
     * Regression: dates round-tripped through the store (saved as DATE_ATOM with
     * a "+00:00" offset) must come back in the *named* "UTC" zone, otherwise the
     * event-config form (model_timezone: 'UTC') throws on render.
     */
    public function testGetDateReturnsNamedUtcZone(): void
    {
        $store = $this->store();

        $store->set(EventConfigStore::KEY_EVENT_START, '2026-09-01T00:00:00+00:00');
        $store->flush();

        $date = $store->getDate(EventConfigStore::KEY_EVENT_START);

        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame('2026-09-01T00:00:00+00:00', $date->format(\DATE_ATOM));
    }
}
