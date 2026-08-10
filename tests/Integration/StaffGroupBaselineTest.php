<?php

namespace App\Tests\Integration;

use App\Security\PrivilegeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Every staff group must be able to reach the page users are sent to after signing in.
 *
 * `LoginFormAuthenticator` sends anyone without a target path to the news index, and that route
 * carries `#[IsGranted('news:view')]`. Onboarding grants the baseline Volunteer group only to
 * non-staff, so a staff group missing this privilege leaves its members with nowhere to land: they
 * finish onboarding, sign in, and are met with a 403 on a page they never chose to visit.
 *
 * ROLE_ADMIN is exempt because it satisfies every privilege check on its own; every other staff role
 * has to hold the privilege outright. Adding a staff group means adding this too - or granting the
 * baseline group instead, but not neither.
 */
final class StaffGroupBaselineTest extends TestCase
{
    private const LANDING_PRIVILEGE = 'news:view';

    public function testEveryStaffGroupCanReachThePostLoginLandingPage(): void
    {
        $missing = [];

        foreach (PrivilegeCatalog::GROUPS as $slug => $group) {
            if (!\in_array($group['role'] ?? null, ['ROLE_STAFF', 'ROLE_SUBADMIN'], true)) {
                continue;
            }

            if (!\in_array(self::LANDING_PRIVILEGE, PrivilegeCatalog::expandPermissions($group['permissions']), true)) {
                $missing[] = $slug;
            }
        }

        self::assertSame(
            [],
            $missing,
            \sprintf(
                'these staff groups cannot reach the page sign-in sends them to: %s',
                implode(', ', $missing),
            ),
        );
    }
}
