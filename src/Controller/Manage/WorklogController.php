<?php

namespace App\Controller\Manage;

use App\Entity\User;
use App\Entity\Worklog;
use App\Form\WorklogType;
use App\Repository\WorklogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/worklogs')]
#[IsGranted('user:worklog:edit')]
final class WorklogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorklogRepository $worklogs,
    ) {
    }

    #[Route('', name: 'app_manage_worklog_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/worklog/index.html.twig', [
            'worklogs' => $this->worklogs->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_manage_worklog_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $worklog = new Worklog($actor);
        $form = $this->createForm(WorklogType::class, $worklog);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $worklog->setCreator($actor);
            $this->em->persist($worklog);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.worklog.flash.logged', [
                '%hours%' => \sprintf('%.2f', $worklog->getHours()),
                '%name%' => $worklog->getUser()->getName(),
            ]));

            return $this->redirectToRoute('app_manage_worklog_index');
        }

        return $this->render('manage/worklog/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.worklog.form.heading_new',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_worklog_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Worklog $worklog): Response
    {
        if ($this->isCsrfTokenValid('delete'.$worklog->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($worklog);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.worklog.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_worklog_index');
    }
}
