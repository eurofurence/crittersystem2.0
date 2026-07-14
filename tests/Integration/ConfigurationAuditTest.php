<?php

namespace App\Tests\Integration;

use App\Audit\AuditEvents;
use App\Repository\AuditEventRepository;
use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;

/**
 * Every change to site-wide configuration — event dates, timezone, access mode, ban thresholds —
 * must leave a record of who changed it.
 *
 * The audit is emitted by EventConfigStore itself rather than by the individual config screens
 * (/manage/configuration, /manage/event-config, /manage/operations), so that a new screen writing
 * through the store cannot forget to audit.
 */
final class ConfigurationAuditTest extends DatabaseTestCase
{
    private function store(): EventConfigStore
    {
        return static::getContainer()->get(EventConfigStore::class);
    }

    private function events(): AuditEventRepository
    {
        return static::getContainer()->get(AuditEventRepository::class);
    }

    public function testChangingConfigurationIsAudited(): void
    {
        $store = $this->store();
        $store->set(EventConfigStore::KEY_TIMEZONE, 'UTC');
        $store->flush();

        $store->set(EventConfigStore::KEY_TIMEZONE, 'Europe/Berlin');
        $store->flush();

        $recorded = $this->events()->findRecent(AuditEvents::CONFIGURATION, null);
        self::assertNotEmpty($recorded, 'a configuration change must be recorded');

        $latest = $recorded[0];
        self::assertSame(AuditEvents::CONFIGURATION, $latest->getEventType());
        self::assertSame(AuditEvents::UPDATE, $latest->getAction());
        self::assertSame(EventConfigStore::KEY_TIMEZONE, $latest->getResourceId());

        $details = $latest->getDetails();
        self::assertSame('Europe/Berlin', $details['new_value']);
        self::assertSame('UTC', $details['old_value'], 'the previous value must be recorded — "from what, to what" is the question asked of an audit log');
    }

    public function testASaveThatChangesNothingIsNotRecordedAsAChange(): void
    {
        $store = $this->store();
        $store->set(EventConfigStore::KEY_NAME, 'Same Value');
        $store->flush();

        $before = \count($this->events()->findRecent(AuditEvents::CONFIGURATION, null));

        $store->set(EventConfigStore::KEY_NAME, 'Same Value');
        $store->flush();

        self::assertCount(
            $before,
            $this->events()->findRecent(AuditEvents::CONFIGURATION, null),
            'a no-op save must not manufacture audit noise — it would bury the real changes',
        );
    }
}
