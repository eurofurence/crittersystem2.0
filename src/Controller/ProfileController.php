<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ProfileAccessService;
use App\Service\ProfilePresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Unified user profile. Own profile at /profile; other users at
 * /users/{id}, gated by the profile visibility rules.
 */
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly ProfilePresenter $presenter,
        private readonly ProfileAccessService $access,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    public function own(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->renderProfile($user, $user);
    }

    #[Route('/users/{id}', name: 'app_profile_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(string $id): Response
    {
        $subject = $this->users->findOneBy(['uuid' => $id]);
        if ($subject === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $viewer */
        $viewer = $this->getUser();
        if (!$this->access->canView($viewer, $subject)) {
            throw $this->createAccessDeniedException();
        }

        return $this->renderProfile($viewer, $subject);
    }

    private function renderProfile(User $viewer, User $subject): Response
    {
        $isSelf = $viewer->getId() === $subject->getId();
        $canViewHistory = $this->access->canViewHistory($viewer, $subject);
        $canViewGoodies = $isSelf || $viewer->hasPrivilege('goodie:view');
        $canViewBans = $viewer->hasPrivilege('user:delete');

        return $this->render('profile/show.html.twig', [
            'header' => $this->presenter->header($subject),
            'isSelf' => $isSelf,
            'canViewHistory' => $canViewHistory,
            'canAddWorklog' => $isSelf && $subject->isStaff(),
            'workHistory' => $canViewHistory ? $this->presenter->workHistory($subject) : null,
            'memberships' => $this->presenter->memberships($subject),
            'goodies' => $canViewGoodies ? $this->presenter->goodies($subject) : null,
            'banReview' => $canViewBans ? $this->presenter->banReview($subject) : null,
        ]);
    }
}
