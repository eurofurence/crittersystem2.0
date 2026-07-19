<?php

namespace App\Controller\Manage;

use App\Entity\News;
use App\Entity\User;
use App\Form\NewsType;
use App\Repository\NewsRepository;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/news')]
#[IsGranted('news:manage')]
final class NewsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsRepository $news,
        private readonly Notifier $notifier,
    ) {
    }

    #[Route('', name: 'app_manage_news_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/news/index.html.twig', [
            'news' => $this->news->findFeed(true),
        ]);
    }

    #[Route('/new', name: 'app_manage_news_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $news = new News();
        $form = $this->createForm(NewsType::class, $news);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            $news->setAuthor($user);
            $this->em->persist($news);
            $this->em->flush();

            if ($request->request->getBoolean('notify')) {
                $sent = $this->notifier->newsPublished($news);
                $this->addFlash('info', new TranslatableMessage('manage.news.flash.notified', ['%count%' => $sent]));
            }
            $this->addFlash('success', new TranslatableMessage('manage.news.flash.published', ['%name%' => $news->getTitle()]));

            return $this->redirectToRoute('app_manage_news_index');
        }

        return $this->render('manage/news/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.news.form.heading_new',
            'showNotify' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_news_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] News $news): Response
    {
        $form = $this->createForm(NewsType::class, $news);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.news.flash.updated', ['%name%' => $news->getTitle()]));

            return $this->redirectToRoute('app_manage_news_index');
        }

        return $this->render('manage/news/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.news.form.heading_edit',
            'showNotify' => false,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_news_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] News $news): Response
    {
        if ($this->isCsrfTokenValid('delete'.$news->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($news);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.news.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_news_index');
    }
}
