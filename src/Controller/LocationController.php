<?php

namespace App\Controller;

use App\Entity\Location;
use App\Service\EmbedSanitizer;
use App\Service\LocationTreeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User-facing locations directory. Staff-only nodes are
 * hidden from non-staff viewers, and a hidden parent never leaks a child.
 */
#[IsGranted('ROLE_USER')]
final class LocationController extends AbstractController
{
    public function __construct(
        private readonly LocationTreeService $tree,
        private readonly EmbedSanitizer $embeds,
    ) {
    }

    #[Route('/locations', name: 'app_locations', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('location/index.html.twig', [
            'tree' => $this->tree->visibleTree($this->getUser()),
        ]);
    }

    #[Route('/locations/{id}', name: 'app_location_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity(mapping: ['id' => 'uuid'])] Location $location): Response
    {
        if (!$this->tree->isVisible($location, $this->getUser())) {
            throw $this->createNotFoundException();
        }

        return $this->render('location/show.html.twig', [
            'location' => $location,
            'embedSrc' => $this->embeds->embedSrc($location),
        ]);
    }
}
