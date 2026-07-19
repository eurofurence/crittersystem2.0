<?php

namespace App\Controller;

use App\Entity\DataExport;
use App\Entity\User;
use App\Gdpr\ErasureService;
use App\Gdpr\GenerateDataExport;
use App\Repository\DataExportRepository;
use App\Storage\ExportStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Self-service GDPR controls on the user's profile: export your data (queued,
 * 24h download link) and request account deletion (right to be forgotten).
 */
#[Route('/profile/privacy')]
#[IsGranted('ROLE_USER')]
final class ProfilePrivacyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DataExportRepository $exports,
        private readonly MessageBusInterface $bus,
        private readonly ErasureService $erasure,
        private readonly ExportStorage $storage,
    ) {
    }

    #[Route('', name: 'app_profile_privacy', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/privacy.html.twig', [
            'exports' => $this->exports->findBy(['user' => $user], ['createdAt' => 'DESC'], 10),
        ]);
    }

    #[Route('/export', name: 'app_profile_data_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('data_export', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_profile_privacy');
        }

        /** @var User $user */
        $user = $this->getUser();
        $export = new DataExport($user, $this->uuid4());
        $this->em->persist($export);
        $this->em->flush();

        $this->bus->dispatch(new GenerateDataExport($export->getId()));
        $this->addFlash('success', new TranslatableMessage('profile.privacy.flash.export_queued'));

        return $this->redirectToRoute('app_profile_privacy');
    }

    #[Route('/export/{uuid}/download', name: 'app_profile_data_download', methods: ['GET'])]
    public function download(string $uuid): Response
    {
        $export = $this->exports->findOneByUuid($uuid);
        if ($export === null || $export->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
        if (!$export->isDownloadable()) {
            $this->addFlash('danger', new TranslatableMessage('profile.privacy.flash.export_unavailable'));

            return $this->redirectToRoute('app_profile_privacy');
        }

        $key = (string) $export->getStorageKey();
        if (!$this->storage->exists($key)) {
            $this->addFlash('danger', new TranslatableMessage('profile.privacy.flash.export_unavailable'));

            return $this->redirectToRoute('app_profile_privacy');
        }

        $export->markDownloaded(new \DateTimeImmutable());
        $this->em->flush();

        return $this->storage->download($key, 'my-data-'.$uuid.'.zip');
    }

    #[Route('/erase-request', name: 'app_profile_erase_request', methods: ['POST'])]
    public function eraseRequest(Request $request): Response
    {
        // Reached only after the two in-app confirmation dialogs.
        if (!$this->isCsrfTokenValid('erase_request', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_profile_privacy');
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->erasure->request($user);
        $this->addFlash('warning', new TranslatableMessage('profile.privacy.flash.erase_requested'));

        return $this->redirectToRoute('app_profile_privacy');
    }

    private function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
