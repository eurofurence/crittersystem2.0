<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Exception\StaleWriteException;
use App\Service\Shift\PublicationService;
use App\Service\Shift\ShiftVisibilityResolver;
use App\Tests\DatabaseTestCase;

/**
 * Draft/publication lifecycle: drafts are invisible until published,
 * publication is atomic and audited, and a stale publish is rejected.
 */
final class PublicationServiceTest extends DatabaseTestCase
{
    private function publisher(): PublicationService
    {
        return static::getContainer()->get(PublicationService::class);
    }

    private function dept(): Department
    {
        $d = new Department('Dept '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);

        return $d;
    }

    private function draft(Department $dept, string $title = 'S', bool $withTask = true): Shift
    {
        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($dept)
            ->setAudience(ShiftAudience::PUBLIC_VOLUNTEER)
            ->setState(ShiftState::DRAFT);
        if ($withTask) {
            $task = new ShiftTask('Task '.bin2hex(random_bytes(3)));
            $this->em->persist($task);
            $shift->setShiftTask($task);
        }
        $this->em->persist($shift);

        return $shift;
    }

    public function testDraftIsInvisibleUntilPublished(): void
    {
        $resolver = static::getContainer()->get(ShiftVisibilityResolver::class);
        $dept = $this->dept();
        $shift = $this->draft($dept);
        $this->em->flush();

        self::assertFalse($resolver->isVisibleTo($shift, null), 'draft is hidden');

        $this->publisher()->publishDepartmentDrafts($dept);
        $this->em->refresh($shift);

        self::assertSame(ShiftState::PUBLISHED, $shift->getState());
        self::assertTrue($resolver->isVisibleTo($shift, null), 'published public shift is visible');
    }

    /**
     * One draft is given an invalid interval (ends equal to starts, written past the store guard),
     * so the whole department publish fails and every draft stays a draft.
     */
    public function testPublishIsAtomicWhenOneShiftIsInvalid(): void
    {
        $dept = $this->dept();
        $good = $this->draft($dept, 'Good');
        $bad = $this->draft($dept, 'Bad');
        $bad->setEndsAt($bad->getStartsAt());
        $this->em->flush();

        $result = $this->publisher()->publishDepartmentDrafts($dept);

        self::assertFalse($result->isSuccessful());
        self::assertSame(0, $result->publishedCount());
        $this->em->refresh($good);
        $this->em->refresh($bad);
        self::assertSame(ShiftState::DRAFT, $good->getState(), 'nothing is published on a validation failure');
        self::assertSame(ShiftState::DRAFT, $bad->getState());
    }

    /** Someone edits the draft after the client last read it, so publishing on the old version is refused. */
    public function testStalePublishIsRejected(): void
    {
        $dept = $this->dept();
        $shift = $this->draft($dept);
        $this->em->flush();
        $staleVersion = $shift->getVersion();

        $shift->setTitle('Edited');
        $this->em->flush();

        $this->expectException(StaleWriteException::class);
        $this->publisher()->publishDepartmentDrafts($dept, [$shift->getId() => $staleVersion]);
    }

    public function testPublishSucceedsWithCurrentVersion(): void
    {
        $dept = $this->dept();
        $shift = $this->draft($dept);
        $this->em->flush();

        $result = $this->publisher()->publishDepartmentDrafts($dept, [$shift->getId() => $shift->getVersion()]);

        self::assertTrue($result->isSuccessful());
        self::assertSame(1, $result->publishedCount());
    }
}
