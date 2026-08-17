<?php

namespace App\Controller;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Entity\Worklog;
use App\Form\WorklogSelfType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Staff self-service worklog. Staff may record hours for themselves
 * and edit/delete only the entries they created for themselves; entries recorded
 * by a manager are locked. All actions are audited.
 */
#[Route('/worklog')]
#[IsGranted('ROLE_USER')]
final class WorklogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/self', name: 'app_worklog_self_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $user = $this->staffUser();
        $worklog = new Worklog($user);
        $worklog->setCreator($user);

        $form = $this->createForm(WorklogSelfType::class, $worklog);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($worklog);
            $this->em->flush();
            $this->audit($worklog, AuditEvents::CREATE);
            $this->addFlash('success', new TranslatableMessage('worklog.flash.added'));
        } else {
            $this->addFlash('danger', new TranslatableMessage('worklog.flash.add_failed'));
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/{id}/edit', name: 'app_worklog_self_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(string $id, Request $request): Response
    {
        $user = $this->staffUser();
        $worklog = $this->ownEditable($id, $user);

        $form = $this->createForm(WorklogSelfType::class, $worklog);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->audit($worklog, AuditEvents::UPDATE);
            $this->addFlash('success', new TranslatableMessage('worklog.flash.updated'));

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('worklog/edit.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/delete', name: 'app_worklog_self_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(string $id, Request $request): Response
    {
        $user = $this->staffUser();
        $worklog = $this->ownEditable($id, $user);

        if ($this->isCsrfTokenValid('worklog-delete-'.$id, (string) $request->request->get('_token'))) {
            $this->em->remove($worklog);
            $this->em->flush();
            $this->audit($worklog, AuditEvents::DELETE);
            $this->addFlash('success', new TranslatableMessage('worklog.flash.deleted'));
        }

        return $this->redirectToRoute('app_profile');
    }

    private function audit(Worklog $worklog, string $action): void
    {
        $this->audit->log(AuditEvents::USER_MANAGEMENT, $action, [
            'resourceType' => 'Worklog',
            'resourceId' => $worklog->getId(),
            'resourceOwnerId' => $worklog->getUser()->getId(),
            'details' => ['hours' => $worklog->getHours(), 'self' => true],
        ]);
    }

    private function staffUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user->isStaff() && !$user->hasPrivilege('worklog:self')) {
            throw $this->createAccessDeniedException('Self-reported worklogs are for staff.');
        }

        return $user;
    }

    /**
     * A worklog is self-editable only when the user is both its subject and the person who recorded
     * it; anything a manager entered stays theirs to change.
     */
    private function ownEditable(string $id, User $user): Worklog
    {
        $worklog = $this->em->getRepository(Worklog::class)->findOneBy(['uuid' => $id]);
        if ($worklog === null) {
            throw $this->createNotFoundException();
        }

        $isOwnSubject = $worklog->getUser()->getId() === $user->getId();
        $isOwnCreator = $worklog->getCreator()?->getId() === $user->getId();
        if (!$isOwnSubject || !$isOwnCreator) {
            throw $this->createAccessDeniedException('This entry was recorded by a manager and cannot be self-edited.');
        }

        return $worklog;
    }
}
