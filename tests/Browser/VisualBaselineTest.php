<?php

namespace App\Tests\Browser;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Security\PrivilegeCatalog;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Screenshots a fixed set of pages so a change to the board's frontend can be shown, rather than
 * argued, to have left the rest of the application alone.
 *
 * The board compiles its own Tailwind stylesheet and loads its own entry point. Every mechanism
 * keeping that off the other pages is asserted by tests/Integration/BoardIsolationTest.php, but
 * those assertions describe the plumbing; this describes the result. A reset leaking through would
 * move type and spacing everywhere at once and pass every functional test in the suite.
 *
 * Not a CI gate and not a regression test - it asserts nothing about pixels. It is a two-run tool:
 *
 *     BOARD_VISUAL=before php bin/phpunit --testsuite Browser --filter VisualBaselineTest
 *     …make the change…
 *     BOARD_VISUAL=after  php bin/phpunit --testsuite Browser --filter VisualBaselineTest
 *     php bin/visual-compare
 *
 * Output goes to var/visual/<label>/ and is gitignored; maintaining committed baselines would cost
 * more than it catches for a set of pages this small.
 */
final class VisualBaselineTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    /**
     * Pages that would visibly move if a global stylesheet changed under them. Chosen for breadth of
     * layout rather than importance: a card grid, dense tables, a form, a nav-heavy hub.
     *
     * Every page here must render identically on two runs of the same code, or a difference says
     * nothing. `/dashboard` cannot be one of them: it prints the viewer's last login, which the
     * sign-in this harness performs updates, so it differs on every run and reports a leak that is
     * not there. `/manage/operations` carries the dense-form coverage instead.
     *
     * @var array<string, string>
     */
    private const PAGES = [
        'operations' => '/manage/operations',
        'news' => '/news',
        'shift-manager' => '/manage-shifts',
        'shifts' => '/shifts',
        'departments' => '/departments',
        'manage-hub' => '/manage',
        'profile' => '/profile',
        'settings' => '/settings',
    ];

    /** Each page is waited on by its body rather than page-specific markup, which keeps the list cheap to extend. */
    public function testCaptureBaseline(): void
    {
        $label = getenv('BOARD_VISUAL') ?: 'before';
        $directory = \dirname(__DIR__, 2).'/var/visual/'.preg_replace('/[^a-z0-9_-]/i', '', $label);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            self::fail('Could not create '.$directory);
        }

        $admin = $this->admin();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1600, 1200));
        $this->signIn($admin, self::PASSWORD);

        foreach (self::PAGES as $name => $path) {
            $client->request('GET', $path);
            $client->waitFor('body', 10);
            $client->takeScreenshot($directory.'/'.$name.'.png');
        }

        foreach (array_keys(self::PAGES) as $name) {
            self::assertFileExists($directory.'/'.$name.'.png');
        }
    }

    /**
     * An administrator, so every page in the list is reachable without per-page privilege wiring.
     *
     * Fixed name and email, unlike the random suffixes the rest of the suite uses. The navbar prints
     * the username on every page, so a random one makes all eight screenshots differ on every run,
     * which is indistinguishable from a stylesheet leaking. The schema is reset per test, so nothing
     * can collide.
     */
    private function admin(): User
    {
        $group = new Group('Visual baseline', 'visual-baseline', 'ROLE_ADMIN');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => PrivilegeCatalog::SUPER])
            ?? new Privilege(PrivilegeCatalog::SUPER);
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('visualbaseline')->setEmail('visual-baseline@example.com')
            ->setApiKey(str_repeat('0', 32));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $user->completeOnboarding();
        $user->addGroup($group);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
