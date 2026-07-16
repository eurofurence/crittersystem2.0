<?php

namespace App\Controller\Admin;

use App\Repository\PrivacyNoticeRepository;
use App\Service\EventConfigStore;
use App\Service\Install\Installer;
use App\Service\Install\InstallStateStore;
use App\Service\Install\MigrationInspector;
use App\Service\SecretCipher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The guided installation / upgrade wizard at /admin/install.
 *
 * It is reachable without any logged-in user (it has to work before an admin
 * exists) and is instead protected by the INSTALL_PASSWORD environment variable
 * plus a session flag. When nothing needs doing — schema current and an admin
 * already exists — every entry redirects back to the site, so the wizard is
 * invisible in normal operation.
 *
 * Steps (each its own page, Tabler "wizard" layout):
 *   1. Welcome + unlock with INSTALL_PASSWORD
 *   2. System overview & dependency checks
 *   3. Database check + run migration (live progress)
 *   4. Create the first administrator (first run only)
 *   5. Optional regional configuration (skippable)
 *   6. Optional privacy-notice essentials (skippable)
 *   7. Finish → back to the site
 */
#[Route('/admin/install')]
final class InstallController extends AbstractController
{
    private const SESSION_AUTH = 'install_authenticated';
    private const STEPS_TOTAL = 6;

    private readonly string $installPassword;

    public function __construct(
        private readonly MigrationInspector $inspector,
        private readonly InstallStateStore $state,
        private readonly Installer $installer,
        private readonly EventConfigStore $config,
        private readonly SecretCipher $cipher,
        private readonly PrivacyNoticeRepository $privacyNotices,
        string $installPassword,
        private readonly string $projectDir,
    ) {
        // Deployment tooling routinely leaves a trailing newline on secret values
        // (a Docker/K8s secret file, `$(cat secret)`, `echo` without -n, or a quoted
        // env_file line). Left in, it makes hash_equals reject the correct password,
        // which then reads to the operator as "wrong password". Normalise it here.
        $this->installPassword = trim($installPassword);
    }

    // ------------------------------------------------------------- step 1: welcome

    #[Route('', name: 'app_install', methods: ['GET'])]
    public function welcome(Request $request): Response
    {
        // The wizard must never be available when there is nothing to do.
        if (!$this->inspector->isInstallNeeded()) {
            return $this->redirectToRoute('app_home');
        }

        if ($this->installerDisabled()) {
            return $this->render('install/disabled.html.twig', [], new Response('', Response::HTTP_FORBIDDEN));
        }

        if ($this->isAuthenticated($request)) {
            return $this->redirectToRoute('app_install_overview');
        }

        return $this->render('install/welcome.html.twig', $this->view(1));
    }

    #[Route('/authenticate', name: 'app_install_authenticate', methods: ['POST'])]
    public function authenticate(Request $request): Response
    {
        if ($this->installerDisabled()) {
            return $this->render('install/disabled.html.twig', [], new Response('', Response::HTTP_FORBIDDEN));
        }

        // A stale/invalid token is a session problem, not a wrong password; say so
        // rather than sending the operator to double-check a password that is correct.
        if (!$this->isCsrfTokenValid('install_authenticate', (string) $request->request->get('_token'))) {
            $this->addFlash('install_error', 'Your setup session expired. Please try again.');

            return $this->redirectToRoute('app_install');
        }

        $submitted = (string) $request->request->get('password', '');
        if (!hash_equals($this->installPassword, $submitted)) {
            $this->addFlash('install_error', 'Incorrect installation password.');

            return $this->redirectToRoute('app_install');
        }

        $request->getSession()->set(self::SESSION_AUTH, true);

        return $this->redirectToRoute('app_install_overview');
    }

    // ------------------------------------------------------------ step 2: overview

    #[Route('/overview', name: 'app_install_overview', methods: ['GET'])]
    public function overview(Request $request): Response
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        $checks = [
            ['label' => 'PHP runtime', 'value' => \PHP_VERSION, 'ok' => \PHP_VERSION_ID >= 80400, 'hint' => 'Requires PHP 8.4 or newer.'],
            ['label' => 'PDO PostgreSQL driver', 'value' => \extension_loaded('pdo_pgsql') ? 'loaded' : 'missing', 'ok' => \extension_loaded('pdo_pgsql'), 'hint' => 'Install the pdo_pgsql extension.'],
            ['label' => 'intl extension', 'value' => \extension_loaded('intl') ? 'loaded' : 'missing', 'ok' => \extension_loaded('intl'), 'hint' => 'Install the intl extension.'],
            ['label' => 'mbstring extension', 'value' => \extension_loaded('mbstring') ? 'loaded' : 'missing', 'ok' => \extension_loaded('mbstring'), 'hint' => 'Install the mbstring extension.'],
            ['label' => 'var/ writable', 'value' => is_writable($this->projectDir . '/var') ? 'writable' : 'not writable', 'ok' => is_writable($this->projectDir . '/var'), 'hint' => 'The web user must be able to write to var/.'],
            ['label' => 'Encryption key', 'value' => $this->cipher->isConfigured() ? 'configured' : 'missing or invalid', 'ok' => $this->cipher->isConfigured(), 'hint' => 'Set APP_ENCRYPTION_KEY (php bin/console app:encryption:generate-key). Required to store SSO, Telegram and certificate secrets.'],
            ['label' => 'Database connection', 'value' => $this->inspector->isDatabaseReachable() ? 'connected' : 'unreachable', 'ok' => $this->inspector->isDatabaseReachable(), 'hint' => 'Check DATABASE_URL and that the database server is running.'],
        ];

        return $this->render('install/overview.html.twig', $this->view(2, [
            'deployment' => $this->detectDeployment(),
            'checks' => $checks,
        ]));
    }

    // ------------------------------------------------------------ step 3: database

    #[Route('/database', name: 'app_install_database', methods: ['GET'])]
    public function database(Request $request): Response
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        // The encryption key must exist before any secret can be stored; setup
        // cannot proceed without it.
        if (!$this->cipher->isConfigured()) {
            $this->addFlash('install_error', 'Set APP_ENCRYPTION_KEY before continuing (php bin/console app:encryption:generate-key).');

            return $this->redirectToRoute('app_install_overview');
        }

        $reachable = $this->inspector->isDatabaseReachable();

        return $this->render('install/database.html.twig', $this->view(3, [
            'reachable' => $reachable,
            'pending' => $reachable ? $this->inspector->pendingMigrationCount() : null,
            'latest' => $this->inspector->latestAvailableVersion(),
            'status' => $this->state->readStatus(),
        ]));
    }

    #[Route('/migrate', name: 'app_install_migrate', methods: ['POST'])]
    public function migrate(Request $request): Response
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('install_migrate', (string) $request->request->get('_token'))) {
            $this->addFlash('install_error', 'Invalid request token, please try again.');

            return $this->redirectToRoute('app_install_database');
        }

        if (!$this->inspector->isDatabaseReachable()) {
            $this->addFlash('install_error', 'Cannot start: the database is not reachable.');

            return $this->redirectToRoute('app_install_database');
        }

        $current = $this->state->readStatus();
        if (($current['state'] ?? null) !== InstallStateStore::STATE_RUNNING) {
            // Provisional "running" so the UI reacts immediately; app:migrate
            // takes ownership of the real status as soon as it boots.
            $this->state->beginMigration();
            $this->launchMigration();
        }

        return $this->redirectToRoute('app_install_database', ['running' => 1]);
    }

    #[Route('/migrate/status', name: 'app_install_migrate_status', methods: ['GET'])]
    public function migrateStatus(Request $request): JsonResponse
    {
        if (!$this->isAuthenticated($request)) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $status = $this->state->readStatus();

        return new JsonResponse([
            'state' => $status['state'],
            'error' => $status['error'],
            'pending' => $this->inspector->isDatabaseReachable() ? $this->inspector->pendingMigrationCount() : null,
            'log' => $this->state->readLog(),
        ]);
    }

    // --------------------------------------------------------------- step 4: admin

    #[Route('/admin', name: 'app_install_admin', methods: ['GET', 'POST'])]
    public function admin(Request $request): Response
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        // Migration must be done before we can write a user row.
        if ($this->inspector->pendingMigrationCount() > 0) {
            return $this->redirectToRoute('app_install_database');
        }

        // Not a fresh install — an admin already exists; skip ahead.
        if ($this->installer->userCount() > 0) {
            return $this->render('install/admin.html.twig', $this->view(4, ['alreadyExists' => true]));
        }

        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username'));
            $email = trim((string) $request->request->get('email'));
            $password = (string) $request->request->get('password');
            $confirm = (string) $request->request->get('password_confirm');

            $errors = [];
            if (!$this->isCsrfTokenValid('install_admin', (string) $request->request->get('_token'))) {
                $errors[] = 'Invalid request token, please try again.';
            }
            if ($username === '') {
                $errors[] = 'Username is required.';
            }
            if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'A valid email address is required.';
            }
            if (\strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
            if ($password !== $confirm) {
                $errors[] = 'Passwords do not match.';
            }

            if ($errors === []) {
                $this->installer->createAdmin($username, $email, $password);
                $this->addFlash('install_success', 'Administrator account created.');

                return $this->redirectToRoute('app_install_config');
            }

            return $this->render('install/admin.html.twig', $this->view(4, [
                'alreadyExists' => false,
                'errors' => $errors,
                'username' => $username,
                'email' => $email,
            ]));
        }

        return $this->render('install/admin.html.twig', $this->view(4, [
            'alreadyExists' => false,
            'username' => 'admin',
            'email' => '',
        ]));
    }

    // -------------------------------------------------------------- step 5: config

    #[Route('/config', name: 'app_install_config', methods: ['GET', 'POST'])]
    public function configure(Request $request): Response
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('install_config', (string) $request->request->get('_token'))) {
                $this->addFlash('install_error', 'Invalid request token, please try again.');

                return $this->redirectToRoute('app_install_config');
            }

            // Skipping is allowed — only persist when the user submitted "save".
            if ($request->request->get('action') === 'save') {
                $eventName = trim((string) $request->request->get('event_name'));
                $timezone = trim((string) $request->request->get('timezone'));
                $dateFormat = trim((string) $request->request->get('date_format'));
                $timeFormat = trim((string) $request->request->get('time_format'));

                if ($eventName !== '') {
                    $this->config->set(EventConfigStore::KEY_NAME, $eventName);
                }
                if ($timezone !== '' && \in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                    $this->config->set(EventConfigStore::KEY_TIMEZONE, $timezone);
                }
                if ($dateFormat !== '') {
                    $this->config->set(EventConfigStore::KEY_DATE_FORMAT, $dateFormat);
                }
                if ($timeFormat !== '') {
                    $this->config->set(EventConfigStore::KEY_TIME_FORMAT, $timeFormat);
                }
                if ($dateFormat !== '' && $timeFormat !== '') {
                    $this->config->set(EventConfigStore::KEY_DATETIME_FORMAT, $dateFormat . ' ' . $timeFormat);
                }
                $this->config->flush();
                $this->addFlash('install_success', 'Configuration saved.');
            }

            return $this->redirectToRoute('app_install_privacy');
        }

        return $this->render('install/config.html.twig', $this->view(5, [
            'eventName' => (string) $this->config->get(EventConfigStore::KEY_NAME, ''),
            'timezone' => (string) $this->config->get(EventConfigStore::KEY_TIMEZONE, EventConfigStore::DEFAULT_TIMEZONE),
            'dateFormat' => (string) $this->config->get(EventConfigStore::KEY_DATE_FORMAT, EventConfigStore::DEFAULT_DATE_FORMAT),
            'timeFormat' => (string) $this->config->get(EventConfigStore::KEY_TIME_FORMAT, EventConfigStore::DEFAULT_TIME_FORMAT),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]));
    }

    // ------------------------------------------------------------- step 6: privacy

    #[Route('/privacy', name: 'app_install_privacy', methods: ['GET', 'POST'])]
    public function privacy(Request $request): Response
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('install_privacy', (string) $request->request->get('_token'))) {
                $this->addFlash('install_error', 'Invalid request token, please try again.');

                return $this->redirectToRoute('app_install_privacy');
            }

            // Skipping is allowed — only persist when the user submitted "save".
            if ($request->request->get('action') === 'save') {
                $this->installer->savePrivacyNotice(
                    trim((string) $request->request->get('event_name')),
                    trim((string) $request->request->get('controller_org')),
                    trim((string) $request->request->get('contact_email')),
                    (int) $request->request->get('deletion_days'),
                );
                $this->addFlash('install_success', 'Privacy notice saved.');
            }

            return $this->redirectToRoute('app_install_finish');
        }

        // Prefill from an existing notice, falling back to the event name just
        // captured in the configuration step.
        $notice = $this->privacyNotices->current();
        $eventName = $notice?->getEventName() ?: (string) $this->config->get(EventConfigStore::KEY_NAME, '');

        return $this->render('install/privacy.html.twig', $this->view(6, [
            'eventName' => $eventName,
            'controllerOrg' => $notice?->getControllerOrg() ?? '',
            'contactEmail' => $notice?->getContactEmail() ?? '',
            'deletionDays' => $notice?->getDeletionDays() ?? 60,
        ]));
    }

    // ------------------------------------------------------------- step 7: finish

    #[Route('/finish', name: 'app_install_finish', methods: ['GET'])]
    public function finish(Request $request): Response
    {
        if (!$this->isAuthenticated($request)) {
            return $this->redirectToRoute('app_install');
        }

        // Refresh the gate's cache so the site reopens immediately, and end the
        // install session.
        $this->state->markReady($this->inspector->latestAvailableVersion());
        $this->state->resetStatus();
        $request->getSession()->remove(self::SESSION_AUTH);

        $this->addFlash('success', 'Setup complete. You can now sign in.');

        return $this->redirectToRoute('app_login');
    }

    // -------------------------------------------------------------------- helpers

    private function installerDisabled(): bool
    {
        return $this->installPassword === '';
    }

    private function isAuthenticated(Request $request): bool
    {
        return $request->getSession()->get(self::SESSION_AUTH) === true;
    }

    /**
     * Common guard for steps 2–5: the installer must be enabled and unlocked.
     */
    private function guard(Request $request): ?RedirectResponse
    {
        if ($this->installerDisabled() || !$this->isAuthenticated($request)) {
            return $this->redirectToRoute('app_install');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function view(int $step, array $extra = []): array
    {
        return array_merge([
            'step' => $step,
            'steps_total' => self::STEPS_TOTAL,
            'progress' => (int) round($step / self::STEPS_TOTAL * 100),
        ], $extra);
    }

    private function detectDeployment(): string
    {
        if (getenv('KUBERNETES_SERVICE_HOST') !== false) {
            return 'Kubernetes';
        }
        if (is_file('/.dockerenv') || getenv('container') !== false) {
            return 'Docker container';
        }

        return 'Direct web server';
    }

    /**
     * Launch `app:migrate` as a detached background process so the wizard can
     * poll for live progress. The advisory lock in the command guarantees this
     * is safe even if it races with a container-startup migration.
     */
    private function launchMigration(): void
    {
        $php = (new PhpExecutableFinder())->find() ?: 'php';

        // Trailing "&" detaches the worker: the shell forks it and returns, so
        // the HTTP request is not held open for the duration of the migration.
        $process = Process::fromShellCommandline(
            \sprintf('%s bin/console app:migrate --no-interaction > /dev/null 2>&1 &', escapeshellarg($php)),
            $this->projectDir,
            $this->subprocessEnv(),
        );
        $process->setTimeout(null);
        $process->disableOutput();
        $process->run();
    }

    /**
     * The handful of environment variables the migration subprocess needs.
     * Passed explicitly because dotenv values are not guaranteed to be exported
     * to the OS environment that a child process inherits.
     *
     * @return array<string, string>
     */
    private function subprocessEnv(): array
    {
        $env = [];
        foreach (['APP_ENV', 'APP_SECRET', 'DATABASE_URL', 'INSTALL_PASSWORD', 'DEFAULT_URI', 'APP_SHARE_DIR'] as $key) {
            if (isset($_SERVER[$key]) && \is_string($_SERVER[$key])) {
                $env[$key] = $_SERVER[$key];
            }
        }

        return $env;
    }
}
