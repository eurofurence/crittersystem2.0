<?php

namespace App\Controller\Manage;

use App\Entity\SsoGroupMapping;
use App\Repository\SsoGroupMappingRepository;
use App\Sso\SsoMappingImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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

    #[Route('/import', name: 'app_manage_sso_mapping_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('sso_import', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_sso_mapping_index');
        }

        $rows = json_decode((string) $request->request->get('json', ''), true);
        if (!\is_array($rows)) {
            $this->addFlash('danger', 'Invalid JSON: expected an array of mappings.');

            return $this->redirectToRoute('app_manage_sso_mapping_index');
        }

        $result = $this->importer->import($rows);
        $this->addFlash('success', \sprintf('Imported %d mapping(s).', $result['imported']));
        foreach (\array_slice($result['warnings'], 0, 20) as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('app_manage_sso_mapping_index');
    }

    #[Route('/{id}/delete', name: 'app_manage_sso_mapping_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, SsoGroupMapping $mapping): Response
    {
        if ($this->isCsrfTokenValid('delete'.$mapping->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($mapping);
            $this->em->flush();
            $this->addFlash('success', 'Mapping deleted.');
        }

        return $this->redirectToRoute('app_manage_sso_mapping_index');
    }
}
