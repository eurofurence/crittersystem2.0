<?php

namespace App\Tests\Integration;

use App\Entity\DutyRecord;
use App\Entity\User;
use App\Service\DutyService;
use App\Tests\DatabaseTestCase;

final class DutyServiceTest extends DatabaseTestCase
{
    private function service(): DutyService
    {
        return static::getContainer()->get(DutyService::class);
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testStartIsIdempotentWhileOnDutyAndEndCloses(): void
    {
        $service = $this->service();
        $user = $this->user('dora');

        self::assertNull($service->getCurrentDuty($user));

        $first = $service->startDuty($user, null);
        self::assertNotNull($service->getCurrentDuty($user));

        // Starting again while on duty returns the same open record (no duplicate).
        $again = $service->startDuty($user, null);
        self::assertSame($first->getId(), $again->getId());
        self::assertCount(1, $this->em->getRepository(DutyRecord::class)->findAll());

        $ended = $service->endDuty($user);
        self::assertNotNull($ended);
        self::assertFalse($ended->isActive());
        self::assertNull($service->getCurrentDuty($user));
    }

    public function testTotalDutyHoursSumsSessions(): void
    {
        $user = $this->user('hank');

        foreach ([1, 2] as $hours) {
            $record = new DutyRecord($user, null);
            $record->setEndedAt($record->getStartedAt()->modify("+{$hours} hours"));
            $this->em->persist($record);
        }
        $this->em->flush();

        self::assertSame(3.0, $this->service()->totalDutyHours($user));
    }
}
