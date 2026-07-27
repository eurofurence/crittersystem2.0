<?php

namespace App\Controller;

use App\Entity\InternalNote;
use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Repository\InternalNoteRepository;
use App\Repository\UserRepository;
use App\Service\UserSearchResultFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Staff-only internal notes: a filterable log of operational notes.
 */
#[Route('/staff/notes')]
#[IsGranted('ROLE_STAFF')]
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
        ]);
    }

    #[Route('/subject-search', name: 'app_staff_notes_subject_search', methods: ['GET'])]
    public function subjectSearch(Request $request, UserSearchResultFormatter $formatter): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        return new JsonResponse($formatter->results($q === '' ? [] : $this->users->searchByName($q)));
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
            if ($deptId = $request->request->get('department')) {
                $note->setDepartment($this->departments->findOneByUuid((string) $deptId));
            }
            if ($subjectUuid = trim((string) $request->request->get('subject'))) {
                $note->setSubjectUser($this->users->findOneByUuid($subjectUuid));
            }

            $this->em->persist($note);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('staff.notes.flash.added'));
        }

        return $this->redirectToRoute('app_staff_notes');
    }

    #[Route('/{id}/delete', name: 'app_staff_notes_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] InternalNote $note): Response
    {
        if ($this->isCsrfTokenValid('note-del'.$note->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($note);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('staff.notes.flash.deleted'));
        }

        return $this->redirectToRoute('app_staff_notes');
    }
}
