<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\BackupTestType;
use App\Storage\StorageDiagnostics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin diagnostics for the S3-backed storage surfaces: a live write/read/delete
 * round-trip for the app's uploads and exports, and a one-shot connectivity test
 * for the database-backup bucket using credentials the admin supplies for the
 * test only (never persisted - the app deliberately does not hold the backup key).
 */
#[IsGranted('global:admin')]
final class StorageController extends AbstractController
{
    public function __construct(private readonly StorageDiagnostics $diagnostics)
    {
    }

    /**
     * The app-storage probe takes no input, so it is guarded by a CSRF token alone; the backup probe
     * runs against credentials the admin types into the form.
     */
    #[Route('/admin/storage', name: 'app_admin_storage', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $appResult = null;
        $backupResult = null;

        if ($request->isMethod('POST') && $request->request->get('action') === 'app_test') {
            if (!$this->isCsrfTokenValid('storage_app_test', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $appResult = [
                'uploads' => $this->diagnostics->probeUploads(),
                'exports' => $this->diagnostics->probeExports(),
            ];
        }

        $backupForm = $this->createForm(BackupTestType::class, $this->backupDefaults());
        $backupForm->handleRequest($request);
        if ($backupForm->isSubmitted() && $backupForm->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $backupForm->getData();
            $backupResult = $this->diagnostics->probeBackup(
                endpoint: trim((string) ($data['endpoint'] ?? '')),
                region: trim((string) ($data['region'] ?? '')),
                bucket: trim((string) ($data['bucket'] ?? '')),
                prefix: trim((string) ($data['prefix'] ?? '')),
                pathStyle: (bool) ($data['pathStyle'] ?? false),
                accessKeyId: trim((string) ($data['accessKeyId'] ?? '')),
                secretAccessKey: (string) ($data['secretAccessKey'] ?? ''),
                runPgDump: (bool) ($data['runPgDump'] ?? false),
            );
        }

        return $this->render('admin/storage/status.html.twig', [
            'uploads' => $this->diagnostics->describeUploads(),
            'exports' => $this->diagnostics->describeExports(),
            'appResult' => $appResult,
            'backupForm' => $backupForm,
            'backupResult' => $backupResult,
        ]);
    }

    /**
     * Pre-fill the non-secret backup fields from the environment when the app
     * happens to have them (a single-host deploy), as a convenience. The secret
     * is never pre-filled.
     *
     * @return array<string, mixed>
     */
    private function backupDefaults(): array
    {
        return [
            'endpoint' => $this->env('BACKUP_S3_ENDPOINT'),
            'region' => $this->env('BACKUP_S3_REGION') ?: 'us-east-1',
            'bucket' => $this->env('BACKUP_S3_BUCKET'),
            'prefix' => $this->env('BACKUP_S3_PREFIX'),
            'pathStyle' => filter_var($this->env('BACKUP_S3_PATH_STYLE'), \FILTER_VALIDATE_BOOL),
            'accessKeyId' => $this->env('BACKUP_S3_ACCESS_KEY_ID'),
            'runPgDump' => true,
        ];
    }

    private function env(string $key): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
