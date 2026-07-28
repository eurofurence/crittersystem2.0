<?php

namespace App\Tests\Integration;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\AuditEvent;
use App\Tests\DatabaseTestCase;

/**
 * Audited identifiers are capped at their column widths.
 *
 * Several of these fields carry unvalidated caller input - actorUsername is whatever was typed into
 * the login form, and a resourceId can be a user-supplied key. Overflowing the column throws while
 * the audit record is being handled, which takes down the very request being audited: posting a
 * long enough username to /login answered 500 instead of "invalid credentials".
 */
final class AuditFieldLengthTest extends DatabaseTestCase
{
    public function testAnOverlongIdentifierIsTruncatedRatherThanBreakingTheWrite(): void
    {
        /** @var AuditLogger $logger */
        $logger = static::getContainer()->get(AuditLogger::class);

        $logger->system(AuditEvents::AUTHENTICATION, AuditEvents::LOGIN_FAILED, [
            'outcome' => AuditEvents::FAILURE,
            'actorUsername' => str_repeat('u', 4000),
            'resourceType' => str_repeat('t', 400),
            'resourceId' => str_repeat('r', 4000),
            'resourceOwnerId' => str_repeat('o', 4000),
        ]);

        $events = $this->em->getRepository(AuditEvent::class)->findAll();
        self::assertCount(1, $events, 'the record has to survive, not just the request');
        self::assertSame(128, mb_strlen((string) $events[0]->getActorUsername()));
        self::assertSame(64, mb_strlen((string) $events[0]->getResourceType()));
        self::assertSame(128, mb_strlen((string) $events[0]->getResourceId()));
    }

    public function testAnOrdinaryIdentifierIsLeftAlone(): void
    {
        /** @var AuditLogger $logger */
        $logger = static::getContainer()->get(AuditLogger::class);

        $logger->system(AuditEvents::AUTHENTICATION, AuditEvents::LOGIN_FAILED, [
            'actorUsername' => 'volunteer@example.com',
            'resourceId' => '42',
        ]);

        $events = $this->em->getRepository(AuditEvent::class)->findAll();
        self::assertSame('volunteer@example.com', $events[0]->getActorUsername());
        self::assertSame('42', $events[0]->getResourceId());
    }
}
