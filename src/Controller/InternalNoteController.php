<?php

namespace App\Controller;

use App\Entity\InternalNote;
use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Repository\InternalNoteRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff-only internal notes: a filterable log of operational notes.
 */
#[Route('/staff/notes')]
#[IsGranted('user.type.staff')]
final class InternalNoteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InternalNoteRepository $notes,
        private readonly DepartmentRepository $departments,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('', name: 'app_staff_notes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $category = (string) $request->query->get('category', '');

        return $this->render('staff/notes.html.twig', [
            'notes' => $this->notes->findFiltered($category !== '' ? $category : null),
            'categories' => InternalNote::CATEGORIES,
            'filterCategory' => $category,
            'departments' => $this->departments->findAllOrdered(),
            'users' => $this->users->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_staff_notes_new', methods: ['POST'])]
    public function create(Request $request): Response
    {
        /** @var User $author */
        $author = $this->getUser();
        $content = trim((string) $request->request->get('content'));

        if ($content !== '' && $this->isCsrfTokenValid('note-new', (string) $request->request->get('_token'))) {
            $note = new InternalNote($author, $content);

            $category = (string) $request->request->get('category', 'general');
            if (\in_array($category, InternalNote::CATEGORIES, true)) {
                $note->setCategory($category);
            }
            if ($deptId = $request->request->getInt('department')) {
                $note->setDepartment($this->departments->find($deptId));
            }
            if ($subjectId = $request->request->getInt('subject')) {
                $note->setSubjectUser($this->users->find($subjectId));
            }

            $this->em->persist($note);
            $this->em->flush();
            $this->addFlash('success', 'Note added.');
        }

        return $this->redirectToRoute('app_staff_notes');
    }

    #[Route('/{id}/delete', name: 'app_staff_notes_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, InternalNote $note): Response
    {
        if ($this->isCsrfTokenValid('note-del'.$note->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($note);
            $this->em->flush();
            $this->addFlash('success', 'Note deleted.');
        }

        return $this->redirectToRoute('app_staff_notes');
    }
}
