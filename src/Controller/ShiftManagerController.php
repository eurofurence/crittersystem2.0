<?php

namespace App\Controller;

use App\Entity\Shift;
use App\Entity\User;
use App\Service\Shift\ShiftFilterMemory;
use App\Exception\CapacityConflictException;
use App\Service\Assignment\EventHoursGuard;
use App\Service\Shift\ShiftApplyDetail;
use App\Service\Shift\ShiftGroupResolver;
use App\Service\Shift\ShiftVisibilityResolver;
use App\Service\Shift\StaffApplyGrid;
use App\Service\ShiftSignupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Staff Shift Manager module. A permission-scoped landing plus the staff shift application view:
 * one day of staff shifts as a department-by-time grid, a dialog per shift carrying the staffing,
 * the reasons the volunteer cannot apply and what to do about each, and transactional apply/cancel.
 */
#[IsGranted('manageshifts:view')]
final class ShiftManagerController extends AbstractController
{
    public function __construct(
        private readonly StaffApplyGrid $grid,
        private readonly ShiftApplyDetail $detail,
        private readonly ShiftVisibilityResolver $visibility,
        private readonly ShiftSignupService $signup,
        private readonly EventHoursGuard $hoursGuard,
        private readonly ShiftGroupResolver $groups,
        private readonly ShiftFilterMemory $filterMemory,
    ) {
    }

    #[Route('/manage-shifts', name: 'app_manage_shifts', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('shift_manager/index.html.twig');
    }

    #[Route('/manage-shifts/apply', name: 'app_manage_shifts_apply', methods: ['GET'])]
    public function apply(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('shift_manager/apply.html.twig', [
            'grid' => $this->grid->build($user, ...$this->filters($request)),
            'filters' => $this->filterParams($request),
        ]);
    }

    /**
     * The dialog for one shift.
     *
     * Rendered by the server rather than assembled in the browser: what a volunteer may do with a
     * shift, and every reason they may not, is decided here and cannot be widened by the page.
     * A shift this viewer may not see is a 404, so the dialog cannot be used to confirm that one
     * exists.
     *
     * No route requirement on the id: the page generates this URL once with an `__ID__` placeholder
     * and substitutes the shift uuid per click, so the placeholder has to pass URL generation. The
     * lookup still resolves by uuid, so anything else is a 404 as well.
     */
    #[Route('/manage-shifts/apply/shift/{id}', name: 'app_manage_shifts_apply_detail', methods: ['GET'])]
    public function shiftDetail(#[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }

        return $this->render('shift_manager/_apply_detail.html.twig', [
            'detail' => $this->detail->build($shift, $user),
            'filters' => $this->filterParams($request),
        ]);
    }

    /**
     * The grid on its own, for the live region: one signal on a department topic re-reads the day
     * the volunteer is looking at, with their filters intact.
     */
    #[Route('/manage-shifts/apply/grid', name: 'app_manage_shifts_apply_grid', methods: ['GET'])]
    public function applyGrid(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('shift_manager/_apply_grid.html.twig', [
            'grid' => $this->grid->build($user, ...$this->filters($request)),
        ]);
    }

    /**
     * Back to the grid the volunteer was looking at. Only the filter keys travel: the day and the
     * departments they had chosen, never whatever else was on the query string.
     */
    private function backToGrid(Request $request): Response
    {
        return $this->redirectToRoute('app_manage_shifts_apply', $this->filterParams($request));
    }

    /**
     * The filters as route parameters, so a redirect or a form action carries the volunteer back to
     * the day and departments they were looking at. Only these keys travel, and only where they
     * differ from the default: my-departments-only is how the screen opens, so carrying it would put
     * a parameter on every URL that says nothing.
     *
     * @return array<string, mixed>
     */
    private function filterParams(Request $request): array
    {
        [$day, $mineOnly, $departments] = $this->filters($request);

        return array_filter([
            'day' => $day,
            'scope' => $mineOnly ? null : 'all',
            'departments' => $departments,
        ]);
    }

    /**
     * The filters in force, from the request when it states any and from the viewer's last visit
     * otherwise, so returning to this screen shows what they were working on.
     *
     * An absent `scope` means the volunteer's own departments; an explicit empty value is the box
     * being unticked and must not read as absent. The remembered set is written in the same shape
     * the query uses, so that distinction survives being stored and read back.
     *
     * The day is taken from the request only and never remembered: it expires, and reopening the
     * screen on a day that has passed looks like a page with no shifts on it.
     *
     * @return array{0: ?string, 1: bool, 2: string[]} day, my-departments-only, department uuids
     */
    private function filters(Request $request): array
    {
        /** @var User $user */
        $user = $this->getUser();

        $day = trim((string) $request->query->get('day', ''));
        $filters = $this->filterMemory->resolve($user, ShiftFilterMemory::SURFACE_APPLY, $request->query->all());

        $departments = $filters['departments'] ?? [];
        $mineOnly = ($filters['scope'] ?? 'mine') === 'mine';

        return [$day === '' ? null : $day, $mineOnly, $departments];
    }

    /**
     * Applying past the recommended hours needs an explicit acknowledgement from the volunteer; it
     * is never assumed on their behalf.
     *
     * A capacity conflict means the shift filled after the page was rendered. It is reported as a
     * warning, and the live region re-reads the grid.
     */
    #[Route('/manage-shifts/apply/{id}', name: 'app_manage_shifts_apply_do', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function doApply(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->visibility->isVisibleTo($shift, $user)) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('apply'.$shift->getId(), (string) $request->request->get('_token'))) {
            $options = $this->signup->signupOptions($shift, $user);
            $type = $options[(string) $request->request->get('volunteer_type')] ?? (\count($options) === 1 ? reset($options) : null);
            if ($type === null) {
                $this->addFlash('danger', new TranslatableMessage('shift_manager.flash.choose_role'));
            } elseif ($this->hoursGuard->wouldExceedGroup($user, $shift) && !$request->request->getBoolean('acknowledge_hours')) {
                $this->addFlash('warning', new TranslatableMessage(
                    'shift_manager.flash.over_hours',
                    ['%count%' => $this->hoursGuard->recommendedMax()],
                ));
            } else {
                try {
                    if ($this->hoursGuard->wouldExceedGroup($user, $shift)) {
                        $this->hoursGuard->acknowledgeSelfApplication($user, $shift);
                    }
                    $this->signup->signUp(
                        $user,
                        $shift,
                        $type,
                        (string) $request->request->get('comment') ?: null,
                        $this->typeChoices($request),
                        $request->request->getBoolean('acknowledge_hours'),
                    );
                    $held = \count($this->groups->entriesFor($shift, $user));
                    $this->addFlash('success', $held > 1
                        ? new TranslatableMessage('shift_manager.flash.applied_group', ['%name%' => $shift->getTitle(), '%count%' => $held])
                        : new TranslatableMessage('shift_manager.flash.applied', ['%name%' => $shift->getTitle()]));
                } catch (CapacityConflictException $e) {
                    $this->addFlash('warning', $e->getMessage());
                } catch (\RuntimeException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->backToGrid($request);
    }

    /**
     * Per-member role choices from the confirmation modal, as member shift uuid => volunteer type uuid.
     *
     * Only the shape is validated here; which roles the volunteer may take is decided by the sign-up
     * service against live data, never by what the form posted.
     *
     * @return array<string, string>
     */
    private function typeChoices(Request $request): array
    {
        $choices = [];
        foreach ($request->request->all('group_type') as $uuid => $typeUuid) {
            if (\is_string($uuid) && \Symfony\Component\Uid\Uuid::isValid($uuid) && \is_string($typeUuid) && \Symfony\Component\Uid\Uuid::isValid($typeUuid)) {
                $choices[$uuid] = $typeUuid;
            }
        }

        return $choices;
    }

    #[Route('/manage-shifts/apply/{id}/cancel', name: 'app_manage_shifts_apply_cancel', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function cancel(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, \App\Repository\ShiftEntryRepository $entries): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $entry = $entries->findOneByShiftAndUser($shift, $user);
        if ($entry !== null && $this->isCsrfTokenValid('cancel'.$shift->getId(), (string) $request->request->get('_token'))) {
            $error = $this->signup->cancelError($entry, false);
            if ($error !== null) {
                $this->addFlash('danger', $error);
            } else {
                $this->signup->cancel($entry);
                $this->addFlash('success', new TranslatableMessage('shift_manager.flash.application_cancelled'));
            }
        }

        return $this->backToGrid($request);
    }
}
