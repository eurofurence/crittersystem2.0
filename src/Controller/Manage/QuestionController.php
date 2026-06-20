<?php

namespace App\Controller\Manage;

use App\Entity\Question;
use App\Entity\User;
use App\Repository\QuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/questions')]
#[IsGranted('question.edit')]
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

    #[Route('/{id}/answer', name: 'app_manage_questions_answer', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function answer(Request $request, Question $question): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        if ($question->isLockedByOther($me)) {
            $this->addFlash('warning', \sprintf('This question is being answered by %s.', $question->getLockedBy()?->getName()));

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
                $this->addFlash('success', 'Answer saved.');
            }

            return $this->redirectToRoute('app_manage_questions_index');
        }

        // Acquire (or refresh) the edit lock for this admin.
        $question->setLockedBy($me)->setLockedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->render('manage/question/answer.html.twig', ['question' => $question]);
    }
}
