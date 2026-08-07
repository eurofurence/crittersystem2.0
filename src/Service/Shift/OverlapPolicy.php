<?php

namespace App\Service\Shift;

use App\Entity\User;

/**
 * Who may hold two shifts that run at the same time.
 *
 * Volunteers may not: a double booking there is a mistake, and the one they turn up for leaves the
 * other unstaffed with nobody expecting it. Staff, sub-admins and admins may: a shift lead covering
 * two rooms, an Info Desk shift running alongside a department duty, and a manager standing in
 * during their own shift are all normal, and refusing them meant the work simply did not get
 * recorded.
 *
 * One implementation because the same question is asked by self-application, by manager assignment
 * and by the dialog that explains a refusal. If they disagreed, a volunteer would be offered a shift
 * that the write then refuses, or told they are blocked by a rule that no longer applies to them.
 */
final class OverlapPolicy
{
    public function blocks(User $user): bool
    {
        return !$user->isStaff();
    }
}
