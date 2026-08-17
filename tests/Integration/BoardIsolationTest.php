<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The operations board runs on a different design system from the rest of the application, and this
 * is what keeps that from being everyone else's problem.
 *
 * Tailwind resets every element and so does Tabler; a page carrying both has no defined appearance.
 * TwigComponent is installed only so the board can use the shadcn kit, and the application's own
 * rule is that reusable markup everywhere else is a Twig macro. Neither of those is enforced by
 * anything a reviewer would notice: the failure is a page that still renders, slightly wrong, or a
 * second component architecture growing quietly beside the first.
 *
 * Each assertion below names the drift it prevents. They are cheap now and they are what catches the
 * change months from now that reuses a kit component on an ordinary page.
 */
final class BoardIsolationTest extends DatabaseWebTestCase
{
    private const ROOT = __DIR__.'/../..';

    /** @return list<string> every Twig template outside the board's own directories */
    private function nonBoardTemplates(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ROOT.'/templates', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/templates/board/') || str_contains($path, '/templates/components/board/')) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    /**
     * The component-architecture rule, made checkable. A `<twig:...>` tag outside the board starts
     * the second component architecture the macro library exists to avoid, and it fails no other
     * test.
     */
    public function testNoTemplateOutsideTheBoardUsesComponentSyntax(): void
    {
        $offenders = [];
        foreach ($this->nonBoardTemplates() as $path) {
            if (preg_match('/<twig:/', (string) file_get_contents($path)) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'TwigComponent is for /board only; everywhere else uses the Twig macros.');
    }

    /** Tailwind reaching the application entry point would apply its reset to every page. */
    public function testTheApplicationEntrypointDoesNotPullInTheBoardsFrontend(): void
    {
        $app = (string) file_get_contents(self::ROOT.'/assets/app.js');

        self::assertStringNotContainsString('board.css', $app);
        self::assertStringNotContainsString('tailwind', $app);
        self::assertStringNotContainsString('board-controllers', $app);
        self::assertStringNotContainsString('components/board', $app);
    }

    /** The inverse: Tabler or Bootstrap on the board would fight Tailwind's reset. */
    public function testTheBoardEntrypointDoesNotPullInTablerOrBootstrap(): void
    {
        $board = (string) file_get_contents(self::ROOT.'/assets/board.js');

        self::assertStringNotContainsString('tabler', $board);
        self::assertStringNotContainsString("'bootstrap'", $board);
        self::assertStringNotContainsString('styles/app.css', $board);
    }

    /**
     * Automatic source detection would let Tailwind generate rules for classes it found anywhere in
     * the project, which is the difference between a board-scoped stylesheet and a global one.
     */
    public function testTailwindScansOnlyTheBoard(): void
    {
        $css = (string) file_get_contents(self::ROOT.'/assets/styles/board.css');

        self::assertStringContainsString("@import 'tailwindcss' source(none);", $css);

        preg_match_all("/@source\s+'([^']+)'/", $css, $matches);
        self::assertNotEmpty($matches[1], 'with source(none) the stylesheet must name its own sources');

        foreach ($matches[1] as $source) {
            self::assertMatchesRegularExpression(
                '#templates/(board|components/board)$#',
                $source,
                'Tailwind may only scan the board and its own components.',
            );
        }
    }

    /** Tailwind must compile the board's stylesheet and nothing else. */
    public function testTailwindsInputIsTheBoardStylesheet(): void
    {
        /** @var array{symfonycasts_tailwind: array{input_css: string|list<string>}} $config */
        $config = Yaml::parseFile(self::ROOT.'/config/packages/symfonycasts_tailwind.yaml');
        $input = (array) $config['symfonycasts_tailwind']['input_css'];

        self::assertSame(['assets/styles/board.css'], $input, 'Tailwind must compile the board stylesheet, and only that.');
    }

    /**
     * Kit components must not join the macro library that the whole application imports from. The
     * macro library is `*_macros.twig` inside per-domain folders, while a kit component is a
     * PascalCase template, so one at the top level means an `ux:install` was never moved.
     */
    public function testKitComponentsLiveInTheirOwnDirectory(): void
    {
        $config = (string) file_get_contents(self::ROOT.'/config/packages/twig_component.yaml');
        self::assertStringContainsString("anonymous_template_directory: 'components/board'", $config);

        $strays = glob(self::ROOT.'/templates/components/[A-Z]*.html.twig') ?: [];
        self::assertSame([], $strays, 'ux:install writes to templates/components/; move its output into board/.');
    }

    /**
     * The kit draws its icons with ux-icons, which this project does not install. A component that
     * still calls for one renders a Twig error rather than an icon, and only at the moment somebody
     * opens the panel that uses it. Every `ux:install` needs the same edit, so this is the reminder.
     */
    public function testNoKitComponentCallsForAnUninstalledIconPackage(): void
    {
        $offenders = [];
        foreach (glob(self::ROOT.'/templates/components/board/{,*/}*.html.twig', \GLOB_BRACE) ?: [] as $path) {
            if (str_contains((string) file_get_contents($path), '<twig:ux:icon')) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame([], $offenders, 'replace ux:icon with the shared icon macro in components/icon/_macros.twig');
    }

    /** The board layout is the only thing that may link the compiled Tailwind output. */
    public function testOnlyTheBoardLinksTheBoardStylesheet(): void
    {
        $offenders = [];
        foreach ($this->nonBoardTemplates() as $path) {
            if (str_contains((string) file_get_contents($path), 'styles/board.css')) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * The stylesheets a page actually loads.
     *
     * Only real `<link rel="stylesheet">` tags count. AssetMapper also lists every CSS file in the
     * page's import map as a `data:` loader module, which is inert unless some JavaScript imports it
     * - so searching the raw HTML for "tabler" reports a leak that does not exist, and would go on
     * reporting it however well isolated the page became.
     *
     * @return list<string>
     */
    private function stylesheetsOn(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        preg_match_all(
            '#<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"#',
            (string) $this->client->getResponse()->getContent(),
            $matches,
        );

        return $matches[1];
    }

    public function testTheApplicationLoadsTablerAndNotTheBoardStylesheet(): void
    {
        $this->client->loginUser($this->boardUser());
        $sheets = $this->stylesheetsOn('/news');

        self::assertNotEmpty(preg_grep('#tabler#', $sheets), 'the application must still be styled by Tabler');
        self::assertSame([], preg_grep('#/styles/board[-.]#', $sheets), 'Tailwind must not reach the application');
        self::assertSame([], preg_grep('#shadcn|tw-animate#', $sheets), 'the kit source must never be served');
    }

    public function testTheBoardLoadsOnlyItsOwnCompiledStylesheet(): void
    {
        $this->client->loginUser($this->boardUser());
        $sheets = $this->stylesheetsOn('/board/'.$this->department->getUuid().'/'.date('Y-m-d'));

        self::assertSame([], preg_grep('#tabler#', $sheets), 'Tabler and Tailwind must not share a page');
        self::assertSame([], preg_grep('#/styles/(app|planner|apply-grid|user-select)[-.]#', $sheets));
        self::assertCount(1, preg_grep('#/styles/board[-.]#', $sheets), 'the board is styled by exactly one stylesheet');
    }

    private Department $department;

    private function boardUser(): User
    {
        $this->department = new Department('Logistics', 'logistics-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->department);

        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        foreach (['board:view', 'news:view'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $this->department));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
