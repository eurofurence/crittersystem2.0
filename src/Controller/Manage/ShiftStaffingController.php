<?php

namespace App\Controller\Manage;

use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\ShiftSignupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/shifts')]
#[IsGranted('admin_shifts')]
final class ShiftStaffingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftSignupService $signup,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly UserRepository $users,
        private readonly ShiftEntryRepository $entries,
    ) {
    }

    #[Route('/{id}/staffing', name: 'app_manage_shift_needs', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function staffing(Request $request, Shift $shift): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        return $this->render('manage/shift/staffing.html.twig', [
            'shift' => $shift,
            'availability' => $this->signup->availability($shift),
            'shiftNeeds' => $shift->getNeededVolunteerTypes(),
            'entries' => $shift->getEntries(),
            'volunteerTypes' => $this->volunteerTypes->findAllOrdered(),
            'q' => $q,
            'searchResults' => $q !== '' ? $this->users->search($q) : [],
        ]);
    }

    #[Route('/{id}/assign', name: 'app_manage_shift_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assign(Request $request, Shift $shift): Response
    {
        if ($this->isCsrfTokenValid('assign'.$shift->getId(), (string) $request->request->get('_token'))) {
            $user = $this->users->find((int) $request->request->get('user'));
            $type = $this->volunteerTypes->find((int) $request->request->get('volunteer_type'));

            if ($user === null || $type === null) {
                $this->addFlash('danger', 'Choose a volunteer and a role.');
            } elseif ($this->entries->findOneByShiftAndUser($shift, $user) !== null) {
                $this->addFlash('warning', \sprintf('%s is already on this shift.', $user->getName()));
            } else {
                // Manager override: capacity/membership are not enforced here.
                $this->em->persist(new ShiftEntry($shift, $type, $user));
                $this->em->flush();
                $this->addFlash('success', \sprintf('Assigned %s as %s.', $user->getName(), $type->getName()));
            }
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shift->getId()]);
    }

    #[Route('/entries/{id}/unassign', name: 'app_manage_shift_entry_unassign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unassign(Request $request, ShiftEntry $entry): Response
    {
        $shiftId = $entry->getShift()->getId();
        if ($this->isCsrfTokenValid('unassign'.$entry->getId(), (string) $request->request->get('_token'))) {
            $name = $entry->getUser()->getName();
            $this->em->remove($entry);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Removed %s from the shift.', $name));
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shiftId]);
    }

    #[Route('/{id}/needs', name: 'app_manage_shift_need_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addNeed(Request $request, Shift $shift): Response
    {
        if ($this->isCsrfTokenValid('need-add'.$shift->getId(), (string) $request->request->get('_token'))) {
            $type = $this->volunteerTypes->find((int) $request->request->get('volunteer_type'));
            $count = max(1, (int) $request->request->get('count', 1));

            if ($type === null) {
                $this->addFlash('danger', 'Choose a volunteer type.');
            } elseif ($shift->getNeededVolunteerTypes()->exists(fn ($k, NeededVolunteerType $n) => $n->getVolunteerType() === $type)) {
                $this->addFlash('warning', \sprintf('"%s" is already listed for this shift.', $type->getName()));
            } else {
                $need = new NeededVolunteerType($type, $count);
                $shift->addNeededVolunteerType($need);
                $this->em->persist($need);
                $this->em->flush();
                $this->addFlash('success', \sprintf('Added %d × %s.', $count, $type->getName()));
            }
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shift->getId()]);
    }

    #[Route('/{id}/needs/{needId}/delete', name: 'app_manage_shift_need_delete', methods: ['POST'], requirements: ['id' => '\d+', 'needId' => '\d+'])]
    public function deleteNeed(Request $request, Shift $shift, int $needId): Response
    {
        if ($this->isCsrfTokenValid('need-del'.$needId, (string) $request->request->get('_token'))) {
            $need = $this->em->getRepository(NeededVolunteerType::class)->find($needId);
            if ($need !== null && $need->getShift() === $shift) {
                $this->em->remove($need);
                $this->em->flush();
                $this->addFlash('success', 'Staffing requirement removed.');
            }
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shift->getId()]);
    }

    #[Route('/entries/{id}/noshow', name: 'app_manage_shift_entry_noshow', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleNoshow(Request $request, ShiftEntry $entry): Response
    {
        if ($this->isCsrfTokenValid('noshow'.$entry->getId(), (string) $request->request->get('_token'))) {
            $entry->setNoshow(!$entry->isNoshow());
            $entry->setNoshowComment($entry->isNoshow() ? (string) $request->request->get('comment') ?: null : null);
            $this->em->flush();
            $this->addFlash('success', $entry->isNoshow() ? 'Marked as no-show.' : 'No-show cleared.');
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $entry->getShift()->getId()]);
    }
}
