<?php

namespace App\Controller;

use App\Entity\Question;
use App\Entity\User;
use App\Repository\QuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Volunteer-facing questions: ask and see your own questions/answers
 */
#[Route('/questions')]
#[IsGranted('question:ask')]
final class QuestionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuestionRepository $questions,
    ) {
    }

    #[Route('', name: 'app_questions_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('question/index.html.twig', [
            'questions' => $this->questions->findByUser($user),
        ]);
    }

    #[Route('/ask', name: 'app_questions_ask', methods: ['POST'])]
    public function ask(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $text = trim((string) $request->request->get('text'));

        if ($text !== '' && $this->isCsrfTokenValid('ask', (string) $request->request->get('_token'))) {
            $this->em->persist(new Question($user, $text));
            $this->em->flush();
            $this->addFlash('success', 'Your question was submitted.');
        }

        return $this->redirectToRoute('app_questions_index');
    }
}
