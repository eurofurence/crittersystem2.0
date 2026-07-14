<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ProfileAccessService;
use App\Storage\FileStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves private user uploads (avatars) through an authorization check, so files
 * are never exposed via a public URL regardless of the storage backend.
 */
#[Route('/media')]
#[IsGranted('ROLE_USER')]
final class MediaController extends AbstractController
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly UserRepository $users,
        private readonly ProfileAccessService $access,
    ) {
    }

    #[Route('/chat/{path<.+>}', name: 'app_media_chat', methods: ['GET'])]
    public function chatImage(string $path): Response
    {
        // Keys are opaque random names under the chat/ prefix; only serve those.
        $key = 'chat/'.basename($path);
        if (!$this->storage->exists($key)) {
            throw $this->createNotFoundException();
        }

        return new Response($this->storage->read($key), Response::HTTP_OK, [
            'Content-Type' => $this->storage->mimeType($key),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    #[Route('/avatar/{id}', name: 'app_media_avatar', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function avatar(string $id): Response
    {
        $subject = $this->users->findOneBy(['uuid' => $id]);
        if ($subject === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$this->access->canView($viewer, $subject)) {
            throw $this->createAccessDeniedException();
        }

        $key = $subject->getPersonalData()?->getAvatarPath();
        if ($key === null || !$this->storage->exists($key)) {
            throw $this->createNotFoundException();
        }

        return new Response($this->storage->read($key), Response::HTTP_OK, [
            'Content-Type' => $this->storage->mimeType($key),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
