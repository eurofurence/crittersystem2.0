<?php

/**
 * Capture the screenshots used by the introduction slide deck.
 *
 * Point this at an instance seeded by `app:demo:seed` and nothing else. The shots land in a slide
 * deck that gets handed around, so a run against a database holding real volunteers would publish
 * their names, e-mail addresses and badge numbers.
 *
 *   php bin/console app:demo:seed          # against a throwaway database
 *   php bin/demo-screenshots.php --base-uri=http://127.0.0.1:8010
 *
 * Chrome comes from ./drivers, the same binaries the Panther test suite uses.
 */

require __DIR__.'/../vendor/autoload.php';

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Symfony\Component\Panther\Client;

/** The event name app:demo:seed writes. Seeing it is how this script knows it is on demo data. */
const DEMO_EVENT_NAME = 'Nordwind Convention 2026';

$options = getopt('', ['base-uri::', 'out::', 'password::', 'only::', 'width::', 'height::', 'scale::']);

$baseUri = $options['base-uri'] ?? 'http://127.0.0.1:8010';
$outDir = $options['out'] ?? __DIR__.'/../docs/slides/img';
$password = $options['password'] ?? 'demo1234';
$only = isset($options['only']) ? array_filter(explode(',', (string) $options['only'])) : null;
$width = (int) ($options['width'] ?? 1440);
$height = (int) ($options['height'] ?? 900);
$scale = (string) ($options['scale'] ?? '2');

if (!is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}
$outDir = realpath($outDir);

/**
 * The shot list.
 *
 * Each entry is [id, role, description] plus keys:
 *   url    - path to open
 *   wait   - CSS selector that must appear before the shot is taken
 *   click  - selector to click after load (for tabs, accordions, modals)
 *   script - JavaScript run just before the shot, for scrolling or opening menus
 *   full   - capture the whole scrollable page instead of one viewport
 *
 * `role` picks the account to be signed in as; the harness only re-authenticates when the role
 * changes, so keep entries for one role together.
 */
$shots = [
    // --- Signed out -------------------------------------------------------
    ['id' => '01-login', 'role' => null, 'url' => '/login', 'wait' => 'form input[name="_username"]'],

    // --- Volunteer --------------------------------------------------------
    ['id' => '10-volunteer-dashboard', 'role' => 'rowan', 'url' => '/dashboard', 'wait' => '.page-body'],
    ['id' => '11-volunteer-shifts-list', 'role' => 'rowan', 'url' => '/shifts', 'wait' => '.page-body'],
    ['id' => '12-volunteer-shift-detail', 'role' => 'rowan', 'url' => '@first-shift', 'wait' => '.page-body'],
    ['id' => '13-volunteer-my-shifts', 'role' => 'rowan', 'url' => '/my-shifts', 'wait' => '.page-body'],
    ['id' => '14-volunteer-types', 'role' => 'rowan', 'url' => '/volunteer-types', 'wait' => '.page-body'],
    ['id' => '15-volunteer-news', 'role' => 'rowan', 'url' => '/news', 'wait' => '.page-body'],
    ['id' => '16-volunteer-faq', 'role' => 'rowan', 'url' => '/faq', 'wait' => '.page-body'],
    ['id' => '17-volunteer-digital-id', 'role' => 'rowan', 'url' => '/digital-id', 'wait' => '.page-body'],
    ['id' => '18-volunteer-profile', 'role' => 'rowan', 'url' => '/profile', 'wait' => '.page-body'],
    ['id' => '19-volunteer-settings', 'role' => 'rowan', 'url' => '/settings', 'wait' => '.page-body'],
    ['id' => '1a-volunteer-privacy', 'role' => 'rowan', 'url' => '/profile/privacy', 'wait' => '.page-body'],
    ['id' => '1b-volunteer-locations', 'role' => 'rowan', 'url' => '/locations', 'wait' => '.page-body'],

    // --- Shift manager ----------------------------------------------------
    ['id' => '20-manager-hub', 'role' => 'sparky', 'url' => '/manage-shifts', 'wait' => '.page-body'],
    [
        'id' => '21-manager-planner', 'role' => 'sparky', 'url' => '/manage-shifts/planner', 'wait' => '#planner-department',
        'select' => ['#planner-department', 'Stage & Tech'],
        'submit' => '#planner-department',
        'waitAfter' => '.page-body',
    ],
    [
        // The grid opens at midnight, where an event schedule is empty. Bring the first real shift
        // into view so the shot shows painted blocks rather than blank rows.
        'id' => '21b-manager-planner-grid', 'role' => 'sparky', 'url' => '/manage-shifts/planner', 'wait' => '#planner-department',
        'select' => ['#planner-department', 'Stage & Tech'],
        'submit' => '#planner-department',
        'waitAfter' => '.planner-block',
        'script' => "document.querySelector('.planner-block')?.scrollIntoView({block: 'center'});",
    ],
    // The wizard and the matrix both open on the first department in the event rather than one the
    // viewer manages, and deny access outright when those differ. Captured as an administrator,
    // who can reach any of them.
    ['id' => '22-manager-wizard', 'role' => 'admin', 'url' => '/manage-shifts/wizard', 'wait' => '.page-body'],
    ['id' => '23-manager-grid', 'role' => 'sparky', 'url' => '/manage-shifts/grid', 'wait' => '.page-body'],
    ['id' => '24-manager-matrix', 'role' => 'admin', 'url' => '/manage-shifts/matrix', 'wait' => '.page-body'],
    ['id' => '25-manager-schedule', 'role' => 'sparky', 'url' => '/manage-shifts/schedule', 'wait' => '.page-body'],
    ['id' => '26-manager-staffing', 'role' => 'sparky', 'url' => '@first-staffing', 'wait' => '.page-body'],
    ['id' => '27-manager-apply', 'role' => 'sparky', 'url' => '/manage-shifts/apply', 'wait' => '.page-body'],
    ['id' => '28-manager-links', 'role' => 'sparky', 'url' => '/manage-shifts/links', 'wait' => '.page-body'],
    ['id' => '29-manager-staff-overview', 'role' => 'sparky', 'url' => '/staff', 'wait' => '.page-body'],

    // --- Department manager ----------------------------------------------
    ['id' => '30-dept-list', 'role' => 'morgan', 'url' => '/departments', 'wait' => '.page-body'],
    ['id' => '31-dept-detail', 'role' => 'morgan', 'url' => '@first-department', 'wait' => '.page-body'],
    ['id' => '32-dept-manage', 'role' => 'morgan', 'url' => '/manage/departments', 'wait' => '.page-body'],
    // Editing volunteer types needs volunteertype:manage, which a department manager does not hold;
    // they assign types rather than define them.
    ['id' => '33-dept-volunteer-types', 'role' => 'admin', 'url' => '/manage/volunteer-types', 'wait' => '.page-body'],
    ['id' => '34-dept-team', 'role' => 'morgan', 'url' => '/staff/team', 'wait' => '.page-body'],
    ['id' => '35-dept-stats', 'role' => 'morgan', 'url' => '/staff/stats', 'wait' => '.page-body'],

    // --- Administrator ----------------------------------------------------
    ['id' => '40-admin-dashboard', 'role' => 'admin', 'url' => '/manage', 'wait' => '.page-body'],
    ['id' => '41-admin-users', 'role' => 'admin', 'url' => '/manage/users', 'wait' => '.page-body'],
    ['id' => '42-admin-groups', 'role' => 'admin', 'url' => '/manage/groups', 'wait' => '.page-body'],
    ['id' => '43-admin-group-matrix', 'role' => 'admin', 'url' => '/manage/groups/matrix', 'wait' => '.page-body'],
    ['id' => '44-admin-configuration', 'role' => 'admin', 'url' => '/manage/configuration', 'wait' => '.page-body'],
    ['id' => '45-admin-event-config', 'role' => 'admin', 'url' => '/manage/event-config', 'wait' => '.page-body'],
    ['id' => '46-admin-locations', 'role' => 'admin', 'url' => '/manage/locations', 'wait' => '.page-body'],
    ['id' => '47-admin-shift-tasks', 'role' => 'admin', 'url' => '/manage/shift-tasks', 'wait' => '.page-body'],
    ['id' => '48-admin-news', 'role' => 'admin', 'url' => '/manage/news', 'wait' => '.page-body'],
    ['id' => '49-admin-certifications', 'role' => 'admin', 'url' => '/manage/certifications', 'wait' => '.page-body'],
    ['id' => '4a-admin-badges', 'role' => 'admin', 'url' => '/manage/badges', 'wait' => '.page-body'],
    ['id' => '4b-admin-worklogs', 'role' => 'admin', 'url' => '/manage/worklogs', 'wait' => '.page-body'],
];

// Panther finds chromedriver on ./drivers by itself but looks for Chrome on PATH, and this
// project keeps its browser next to the driver.
$bundledChrome = __DIR__.'/../drivers/chrome-linux64/chrome';
if (!isset($_SERVER['PANTHER_CHROME_BINARY']) && is_executable($bundledChrome)) {
    $_SERVER['PANTHER_CHROME_BINARY'] = $bundledChrome;
}

$client = Client::createChromeClient(
    __DIR__.'/../drivers/chromedriver',
    [
        '--headless=new',
        '--no-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--hide-scrollbars',
        '--force-device-scale-factor='.$scale,
        '--window-size='.$width.','.$height,
        '--lang=en-GB',
    ],
    [],
    $baseUri,
);

/**
 * Hide anything that is an artefact of the development environment rather than part of the
 * product: the Symfony debug toolbar, and the caret that marks a focused field.
 */
$cleanUp = <<<'JS'
    document.querySelectorAll('.sf-toolbar, .sf-minitoolbar, #sfwdt, .sf-dump').forEach(el => el.remove());
    document.activeElement && document.activeElement.blur();
    document.body.style.caretColor = 'transparent';
JS;

/*
 * Refuse to photograph anything but the seeded demo event.
 *
 * Pointing this at an instance backed by a real database publishes real volunteers' names and
 * badge numbers into a slide deck, and nothing later in the process would notice. The check is
 * cheap and it has already caught one misconfigured server: PHP's built-in server does not put the
 * environment into $_SERVER, so an exported DATABASE_URL can be silently overridden by .env.
 */
$client->request('GET', $baseUri.'/login');
$client->waitFor('body', 15);
$landing = (string) $client->executeScript('return document.body.innerText;');
if (!str_contains($landing, DEMO_EVENT_NAME)) {
    fwrite(STDERR, sprintf(
        "Refusing to run: %s does not look like the demo instance.\n"
        ."Expected the event name %s on the login page. Start it with bin/demo-instance.\n",
        $baseUri,
        DEMO_EVENT_NAME,
    ));
    $client->quit();
    exit(1);
}

$signedInAs = null;
$captured = [];
$failed = [];

$signIn = static function (?string $username) use ($client, $password, $baseUri): void {
    $client->request('GET', $baseUri.'/logout');
    usleep(300_000);
    // Form CSRF here is a stateless double-submit between a hidden field and a cookie. The cookie
    // left behind by the previous session makes the next login POST look like a downgraded token
    // and it is rejected, silently, leaving the browser sitting on /login. Start each role from no
    // cookies at all.
    $client->getWebDriver()->manage()->deleteAllCookies();
    if ($username === null) {
        return;
    }
    $client->request('GET', $baseUri.'/login');
    $client->waitFor('form input[name="_username"]', 15);
    // Where an identity provider is configured the credential form ships collapsed. WebDriver
    // cannot type into a hidden field, so open it before filling it in.
    $client->executeScript("document.getElementById('password-login')?.classList.add('show');");
    usleep(300_000);
    $client->submitForm('Sign in', ['_username' => $username, '_password' => $password]);

    // Wait on the URL rather than on page text: the destination differs per role and several
    // signed-in pages legitimately contain the words "sign in".
    for ($waited = 0; $waited < 150; ++$waited) {
        if (!str_contains($client->getCurrentURL(), '/login')) {
            return;
        }
        usleep(100_000);
    }

    throw new RuntimeException(sprintf('sign-in as "%s" did not leave /login', $username));
};

/** Follow a link on a list page so detail shots never hard-code a UUID. */
$resolveDynamic = static function (string $token) use ($client, $baseUri): ?string {
    $sources = [
        // Filtered to what this account may actually join, so the detail shot shows a sign-up
        // button rather than "not available".
        '@first-shift' => ['/shifts?available=1', 'a[href^="/shifts/"]'],
        '@first-staffing' => ['/manage/shifts', 'a[href*="/staffing"]'],
        '@first-department' => ['/departments', 'a[href^="/departments/"]'],
    ];
    if (!isset($sources[$token])) {
        return null;
    }
    [$listUrl, $selector] = $sources[$token];
    $client->request('GET', $baseUri.$listUrl);
    $client->waitFor('.page-body', 15);
    foreach ($client->getWebDriver()->findElements(WebDriverBy::cssSelector($selector)) as $link) {
        $href = (string) $link->getAttribute('href');
        if ($href !== '' && !str_contains($href, '#')) {
            return $href;
        }
    }

    return null;
};

foreach ($shots as $shot) {
    $id = $shot['id'];
    if ($only !== null && !in_array($id, $only, true)) {
        continue;
    }

    $role = $shot['role'] ?? null;
    if ($role !== $signedInAs) {
        $signIn($role);
        $signedInAs = $role;
    }

    try {
        $url = $shot['url'];
        if (str_starts_with($url, '@')) {
            $resolved = $resolveDynamic($url);
            if ($resolved === null) {
                throw new RuntimeException("could not resolve {$url}");
            }
            $url = $resolved;
        } elseif (!str_starts_with($url, 'http')) {
            $url = $baseUri.$url;
        }

        $client->request('GET', $url);
        $client->waitFor($shot['wait'] ?? 'body', 15);

        if (isset($shot['select'])) {
            [$selector, $label] = $shot['select'];
            $chosen = $client->executeScript(sprintf(
                'const el = document.querySelector(%s);'
                .'if (!el) { return false; }'
                .'const opt = [...el.options].find(o => o.textContent.trim() === %s);'
                .'if (!opt) { return false; }'
                .'el.value = opt.value;'
                .'el.dispatchEvent(new Event("change", {bubbles: true}));'
                .'return true;',
                json_encode($selector),
                json_encode($label),
            ));
            if ($chosen !== true) {
                throw new RuntimeException("no option \"{$label}\" in {$selector}");
            }
            usleep(300_000);
        }

        if (isset($shot['submit'])) {
            $client->executeScript(sprintf(
                'document.querySelector(%s)?.form?.submit();',
                json_encode($shot['submit']),
            ));
            usleep(900_000);
        }

        if (isset($shot['click'])) {
            $client->waitFor($shot['click'], 10);
            $client->getWebDriver()->findElement(WebDriverBy::cssSelector($shot['click']))->click();
            usleep(700_000);
        }

        if (isset($shot['waitAfter'])) {
            $client->waitFor($shot['waitAfter'], 15);
        }

        usleep(900_000); // let fonts, icons and any deferred fragment settle
        $client->executeScript($cleanUp);
        if (isset($shot['script'])) {
            $client->executeScript($shot['script']);
            usleep(400_000);
        }

        if (!empty($shot['full'])) {
            $pageHeight = (int) $client->executeScript('return Math.min(document.body.scrollHeight, 4000);');
            $client->manage()->window()->setSize(new WebDriverDimension($width, max($height, $pageHeight)));
            usleep(500_000);
            $client->executeScript($cleanUp);
        }

        $path = $outDir.'/'.$id.'.png';
        $client->takeScreenshot($path);

        if (!empty($shot['full'])) {
            $client->manage()->window()->setSize(new WebDriverDimension($width, $height));
        }

        $title = trim((string) $client->executeScript('return document.title;'));
        $captured[$id] = ['role' => $role ?? 'signed out', 'title' => $title, 'url' => $url];
        printf("  ok    %-28s %s\n", $id, $title);
    } catch (Throwable $e) {
        $failed[$id] = $e->getMessage();
        printf("  FAIL  %-28s %s\n", $id, explode("\n", $e->getMessage())[0]);
    }
}

$client->quit();

file_put_contents(
    $outDir.'/manifest.json',
    json_encode(['captured' => $captured, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

printf("\n%d captured, %d failed. Manifest: %s/manifest.json\n", count($captured), count($failed), $outDir);

exit($failed === [] ? 0 : 1);
