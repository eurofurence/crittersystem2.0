<?php

namespace App\Controller;

use App\Entity\News;
use App\Entity\NewsComment;
use App\Entity\User;
use App\Repository\NewsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public news feed, article view and commenting.
 */
#[Route('/news')]
#[IsGranted('news')]
final class NewsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsRepository $news,
    ) {
    }

    #[Route('', name: 'app_news_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('news/index.html.twig', [
            'items' => $this->news->findFeed($this->canSeeStaffOnly()),
        ]);
    }

    #[Route('/{id}', name: 'app_news_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(News $news): Response
    {
        if ($news->isStaffOnly() && !$this->canSeeStaffOnly()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('news/show.html.twig', ['news' => $news]);
    }

    #[Route('/{id}/comment', name: 'app_news_comment', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function comment(Request $request, News $news): Response
    {
        if ($news->isStaffOnly() && !$this->canSeeStaffOnly()) {
            throw $this->createAccessDeniedException();
        }

        $text = trim((string) $request->request->get('text'));
        if ($this->isCsrfTokenValid('comment'.$news->getId(), (string) $request->request->get('_token')) && $text !== '') {
            /** @var User $user */
            $user = $this->getUser();
            $this->em->persist(new NewsComment($news, $user, $text));
            $this->em->flush();
            $this->addFlash('success', 'Comment posted.');
        }

        return $this->redirectToRoute('app_news_show', ['id' => $news->getId()]);
    }

    private function canSeeStaffOnly(): bool
    {
        return $this->isGranted('user.type.staff')
            || $this->isGranted('user.type.internal_staff')
            || $this->isGranted('admin');
    }
}
