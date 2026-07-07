<?php

namespace App\Controller\Manage;

use App\Entity\Badge;
use App\Form\BadgeType;
use App\Repository\BadgeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/manage/badges')]
#[IsGranted('badge:manage')]
final class BadgeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BadgeRepository $badges,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'app_manage_badge_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/badge/index.html.twig', ['badges' => $this->badges->findAllOrdered()]);
    }

    #[Route('/new', name: 'app_manage_badge_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $badge = new Badge();

        return $this->handle($request, $badge, true);
    }

    #[Route('/{id}/edit', name: 'app_manage_badge_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Badge $badge): Response
    {
        return $this->handle($request, $badge, false);
    }

    #[Route('/{id}/delete', name: 'app_manage_badge_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Badge $badge): Response
    {
        if ($this->isCsrfTokenValid('delete'.$badge->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($badge);
            $this->em->flush();
            $this->addFlash('success', 'Badge deleted.');
        }

        return $this->redirectToRoute('app_manage_badge_index');
    }

    private function handle(Request $request, Badge $badge, bool $isNew): Response
    {
        $form = $this->createForm(BadgeType::class, $badge);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($badge->getSlug() === '') {
                $badge->setSlug($this->uniqueSlug($badge->getName()));
            }
            if ($isNew) {
                $this->em->persist($badge);
            }
            $this->em->flush();
            $this->addFlash('success', \sprintf('Badge "%s" saved.', $badge->getName()));

            return $this->redirectToRoute('app_manage_badge_index');
        }

        return $this->render('manage/badge/form.html.twig', [
            'form' => $form,
            'heading' => $isNew ? 'New badge' : 'Edit badge',
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower($this->slugger->slug($name)->toString()) ?: 'badge';
        $slug = $base;
        $n = 2;
        while ($this->badges->findOneBySlug($slug) !== null) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
