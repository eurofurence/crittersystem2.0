<?php

namespace App\Controller\Manage;

use App\Sso\UserPreSeedImporter;
use App\Storage\FileStorage;
use League\Flysystem\FilesystemException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Bulk pre-seeding of users from an identity-provider group dump. Two-step: an upload produces a
 * no-write preview, and an explicit confirm applies it. Gated by the {@see \App\Security\PrivilegeCatalog}
 * `user:preseed` privilege, which is admin-level and flagged twoFactor, so reaching either write
 * requires a global admin with a fresh two-factor step-up (enforced by TwoFactorStepUpSubscriber).
 */
#[Route('/manage/user-preseed')]
#[IsGranted('user:preseed')]
final class UserPreSeedController extends AbstractController
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly UserPreSeedImporter $importer,
        private readonly FileStorage $storage,
    ) {
    }

    #[Route('', name: 'app_manage_user_preseed_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/user_preseed/index.html.twig');
    }

    #[Route('/preview', name: 'app_manage_user_preseed_preview', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('preseed_upload', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_user_preseed_index');
        }

        $file = $request->files->get('file');
        if ($file === null) {
            $this->addFlash('danger', new TranslatableMessage('manage.preseed.flash.no_file'));

            return $this->redirectToRoute('app_manage_user_preseed_index');
        }
        if ($file->getSize() !== null && $file->getSize() > self::MAX_BYTES) {
            $this->addFlash('danger', new TranslatableMessage('manage.preseed.flash.too_large'));

            return $this->redirectToRoute('app_manage_user_preseed_index');
        }

        $contents = (string) file_get_contents($file->getPathname());
        $rows = json_decode($contents, true);
        if (!\is_array($rows) || !array_is_list($rows)) {
            $this->addFlash('danger', new TranslatableMessage('manage.preseed.flash.invalid_json'));

            return $this->redirectToRoute('app_manage_user_preseed_index');
        }

        $token = bin2hex(random_bytes(16));
        $this->storage->write($this->bufferKey($token), $contents, 'application/json');

        // Post/Redirect/Get: the preview is rendered by the GET below. Turbo drops a
        // non-redirecting response to a form submission, so the upload must redirect
        // rather than render the preview directly. It also makes the preview refreshable.
        return $this->redirectToRoute('app_manage_user_preseed_preview_show', ['token' => $token]);
    }

    #[Route('/preview/{token}', name: 'app_manage_user_preseed_preview_show', methods: ['GET'], requirements: ['token' => '[0-9a-f]{32}'])]
    public function preview(string $token): Response
    {
        $rows = $this->readBufferedRows($token);
        if ($rows === null) {
            $this->addFlash('danger', new TranslatableMessage('manage.preseed.flash.expired'));

            return $this->redirectToRoute('app_manage_user_preseed_index');
        }

        return $this->render('manage/user_preseed/preview.html.twig', [
            'preview' => $this->importer->preview($rows),
            'token' => $token,
        ]);
    }

    #[Route('/apply', name: 'app_manage_user_preseed_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('preseed_apply', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_user_preseed_index');
        }

        $token = (string) $request->request->get('token');
        $rows = ctype_xdigit($token) ? $this->readBufferedRows($token) : null;
        if ($rows === null) {
            $this->addFlash('danger', new TranslatableMessage('manage.preseed.flash.expired'));

            return $this->redirectToRoute('app_manage_user_preseed_index');
        }

        $result = $this->importer->import($rows);
        $this->discard($this->bufferKey($token));

        $this->addFlash('success', new TranslatableMessage('manage.preseed.flash.done', [
            '%created%' => $result['created'],
            '%updated%' => $result['updated'],
            '%skipped%' => $result['skippedUsers'] + $result['skippedBanned'],
        ]));
        foreach (array_slice($result['warnings'], 0, 20) as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('app_manage_user_preseed_index');
    }

    private function bufferKey(string $token): string
    {
        return sprintf('preseed/%s.json', $token);
    }

    /**
     * Decode the buffered upload for a token, or null if it is gone, unreadable or
     * not a JSON list. Only validated JSON lists are ever written, so a null here
     * means an expired or tampered buffer, not a bad original upload.
     *
     * @return list<mixed>|null
     */
    private function readBufferedRows(string $token): ?array
    {
        $key = $this->bufferKey($token);
        try {
            if (!$this->storage->exists($key)) {
                return null;
            }
            $contents = $this->storage->read($key);
        } catch (FilesystemException) {
            return null;
        }

        $rows = json_decode($contents, true);
        if (!\is_array($rows) || !array_is_list($rows)) {
            return null;
        }

        return $rows;
    }

    private function discard(string $key): void
    {
        try {
            $this->storage->delete($key);
        } catch (FilesystemException) {
            // A leftover buffer is harmless; it is admin-only and overwritten by the next upload.
        }
    }
}
