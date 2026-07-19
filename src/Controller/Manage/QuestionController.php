<?php

namespace App\Controller\Manage;

use App\Entity\Question;
use App\Entity\User;
use App\Repository\QuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/questions')]
#[IsGranted('question:answer')]
final class QuestionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuestionRepository $questions,
    ) {
    }

    #[Route('', name: 'app_manage_questions_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/question/index.html.twig', [
            'questions' => $this->questions->findForModeration(),
        ]);
    }

    #[Route('/{id}/answer', name: 'app_manage_questions_answer', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function answer(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Question $question): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        if ($question->isLockedByOther($me)) {
            $this->addFlash('warning', new TranslatableMessage('manage.question.flash.locked', ['%name%' => $question->getLockedBy()?->getName()]));

            return $this->redirectToRoute('app_manage_questions_index');
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('answer'.$question->getId(), (string) $request->request->get('_token'))) {
                $question->setAnswer(trim((string) $request->request->get('answer')) ?: null)
                    ->setAnswerer($me)
                    ->setAnsweredAt(new \DateTimeImmutable())
                    ->setLockedBy(null)
                    ->setLockedAt(null);
                $this->em->flush();
                $this->addFlash('success', new TranslatableMessage('manage.question.flash.answer_saved'));
            }

            return $this->redirectToRoute('app_manage_questions_index');
        }

        // Acquire (or refresh) the edit lock for this admin.
        $question->setLockedBy($me)->setLockedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->render('manage/question/answer.html.twig', ['question' => $question]);
    }
}
