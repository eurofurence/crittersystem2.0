<?php

namespace App\Controller\Manage;

use App\Entity\SsoGroupMapping;
use App\Form\SsoMappingType;
use App\Repository\SsoGroupMappingRepository;
use App\Sso\SsoMappingImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Administration of SSO group mappings, primarily via JSON bulk upload.
 */
#[Route('/manage/sso-mappings')]
#[IsGranted('rbac:ssomap:manage')]
final class SsoMappingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SsoGroupMappingRepository $mappings,
        private readonly SsoMappingImporter $importer,
    ) {
    }

    #[Route('', name: 'app_manage_sso_mapping_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/sso_mapping/index.html.twig', ['mappings' => $this->mappings->findAllOrdered()]);
    }

    #[Route('/export', name: 'app_manage_sso_mapping_export', methods: ['GET'])]
    public function export(): Response
    {
        $json = json_encode($this->importer->export(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $response = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'sso-mappings.json',
        ));

        return $response;
    }

    /**
     * An uploaded file wins over the textarea, which holds the pasted contents. The modal always
     * submits both fields, so an empty (no-file) upload has to fall through instead of being read.
     */
    #[Route('/import', name: 'app_manage_sso_mapping_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('sso_import', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_sso_mapping_index');
        }

        $upload = $request->files->get('file');
        $payload = $upload !== null && $upload->isValid()
            ? (string) $upload->getContent()
            : (string) $request->request->get('json', '');
        if (trim($payload) === '') {
            $this->addFlash('danger', new TranslatableMessage('manage.import.flash.no_json'));

            return $this->redirectToRoute('app_manage_sso_mapping_index');
        }

        $rows = json_decode($payload, true);
        if (!\is_array($rows) || array_is_list($rows) === false) {
            $this->addFlash('danger', new TranslatableMessage('manage.sso_mapping.flash.invalid_json'));

            return $this->redirectToRoute('app_manage_sso_mapping_index');
        }

        $result = $this->importer->import($rows);
        $this->addFlash('success', new TranslatableMessage('manage.sso_mapping.flash.imported', ['%count%' => $result['imported']]));
        foreach (\array_slice($result['warnings'], 0, 20) as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('app_manage_sso_mapping_index');
    }

    #[Route('/new', name: 'app_manage_sso_mapping_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handle($request, new SsoGroupMapping(), true);
    }

    #[Route('/{id}/edit', name: 'app_manage_sso_mapping_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] SsoGroupMapping $mapping): Response
    {
        return $this->handle($request, $mapping, false);
    }

    #[Route('/{id}/delete', name: 'app_manage_sso_mapping_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] SsoGroupMapping $mapping): Response
    {
        if ($this->isCsrfTokenValid('delete'.$mapping->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($mapping);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.sso_mapping.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_sso_mapping_index');
    }

    private function handle(Request $request, SsoGroupMapping $mapping, bool $isNew): Response
    {
        $form = $this->createForm(SsoMappingType::class, $mapping);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $existing = $this->mappings->findOneBySsoGroupId($mapping->getSsoGroupId());
            if ($existing !== null && $existing !== $mapping) {
                $form->get('ssoGroupId')->addError(new FormError('An SSO group with this id already exists.'));
            } else {
                if ($isNew) {
                    $this->em->persist($mapping);
                }
                $this->em->flush();
                $this->addFlash('success', new TranslatableMessage('manage.sso_mapping.flash.saved', ['%name%' => $mapping->getName()]));

                return $this->redirectToRoute('app_manage_sso_mapping_index');
            }
        }

        return $this->render('manage/sso_mapping/form.html.twig', [
            'form' => $form,
            'heading' => $isNew ? 'manage.sso_mapping.form.heading_new' : 'manage.sso_mapping.form.heading_edit',
        ]);
    }
}
