<?php

namespace App\Service\Shift;

use App\Entity\ShiftEntry;
use App\Mercure\ShiftSignal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recording that somebody is, or is no longer, working a shift they are assigned to.
 *
 * The mutation lives here rather than in the caller so the change is announced wherever it comes
 * from. Today that is only the Telegram bot's manager endpoints, and the operations board reads the
 * result: without a signal, a manager checking somebody in on their phone would leave every board in
 * the department showing the position as unattended until something unrelated happened.
 *
 * Publishing is queued and flushed on kernel.terminate, never sent inline, so a signal cannot
 * overtake its own transaction and tell a browser to re-read a row that is not committed yet.
 */
final class ShiftAttendanceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftSignal $signal,
    ) {
    }

    public function checkIn(ShiftEntry $entry, ?\DateTimeImmutable $at = null): ShiftEntry
    {
        $entry->checkIn($at ?? new \DateTimeImmutable());
        $this->em->flush();
        $this->signal->staffingChanged($entry->getShift(), $entry->getUser());

        return $entry;
    }

    public function checkOut(ShiftEntry $entry, ?\DateTimeImmutable $at = null): ShiftEntry
    {
        $entry->checkOut($at ?? new \DateTimeImmutable());
        $this->em->flush();
        $this->signal->staffingChanged($entry->getShift(), $entry->getUser());

        return $entry;
    }

    /** Recording a no-show changes what the board shows about the shift, so it announces too. */
    public function markNoShow(ShiftEntry $entry, ?string $comment): ShiftEntry
    {
        $entry->setNoshow(true);
        $entry->setNoshowComment($comment);
        $this->em->flush();
        $this->signal->staffingChanged($entry->getShift(), $entry->getUser());

        return $entry;
    }

    /**
     * Taking a no-show back. The comment goes with it: it described an absence that is no longer
     * recorded, and leaving it behind would keep accusing somebody the manager has just cleared.
     */
    public function clearNoShow(ShiftEntry $entry): ShiftEntry
    {
        $entry->setNoshow(false);
        $entry->setNoshowComment(null);
        $this->em->flush();
        $this->signal->staffingChanged($entry->getShift(), $entry->getUser());

        return $entry;
    }
}
