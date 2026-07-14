<?php

namespace App\Controller\Manage;

use App\Entity\ConsentText;
use App\Form\ConsentTextType;
use App\Repository\ConsentTextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/consent-texts')]
#[IsGranted('config:consent')]
final class ConsentTextController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConsentTextRepository $texts,
    ) {
    }

    #[Route('', name: 'app_manage_consent_text_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/consent_text/index.html.twig', [
            'texts' => $this->texts->findBy([], ['locale' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_manage_consent_text_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $text = new ConsentText();

        return $this->handleForm($request, $text, true);
    }

    #[Route('/{id}/edit', name: 'app_manage_consent_text_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ConsentText $text): Response
    {
        return $this->handleForm($request, $text, false);
    }

    #[Route('/{id}/delete', name: 'app_manage_consent_text_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ConsentText $text): Response
    {
        if ($this->isCsrfTokenValid('delete'.$text->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($text);
            $this->em->flush();
            $this->addFlash('success', 'Consent text deleted.');
        }

        return $this->redirectToRoute('app_manage_consent_text_index');
    }

    private function handleForm(Request $request, ConsentText $text, bool $isNew): Response
    {
        $form = $this->createForm(ConsentTextType::class, $text);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($text);
            }
            $this->em->flush();
            $this->addFlash('success', \sprintf('Consent text for "%s" saved.', $text->getLocale()));

            return $this->redirectToRoute('app_manage_consent_text_index');
        }

        return $this->render('manage/consent_text/form.html.twig', [
            'form' => $form,
            'heading' => $isNew ? 'New consent text' : 'Edit consent text',
        ]);
    }
}
