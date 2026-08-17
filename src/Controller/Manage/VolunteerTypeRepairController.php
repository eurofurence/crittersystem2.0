<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\UserRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\Volunteer\DefaultVolunteerTypeAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Diagnoses and repairs users who finished onboarding without a volunteer type.
 *
 * Onboarding matches the base type on its role, not its name. A type created from the volunteer
 * type screen carries no role, so an event that recreates its base type rather than renaming the
 * seeded one leaves the lookup finding nothing: every non-staff user then completes onboarding,
 * is told it worked, and holds no membership. Shift eligibility asks for a confirmed membership,
 * so those users cannot take a shift and nothing on screen says why.
 *
 * The unclaimed role is shown before the affected users on purpose. Repairing users while no type
 * claims the role assigns nobody anything, so the screen has to lead with the cause.
 *
 * `volunteertype:manage` gates this rather than `volunteertype:assign`, which is department
 * scoped: a scoped privilege handed no subject grants unconditionally, and this screen acts on
 * users across every department at once.
 */
#[Route('/manage/volunteer-types/repair')]
#[IsGranted('volunteertype:manage')]
final class VolunteerTypeRepairController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly DefaultVolunteerTypeAssigner $assigner,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * The page number is cast rather than read with getInt(), which throws on a value it cannot
     * convert: a blank or hand-edited one shows the first page instead of answering 400.
     *
     * Each row's target type is resolved here rather than in the template, because it depends on
     * whether the user counts as staff and a template cannot ask the assigner.
     */
    #[Route('', name: 'app_manage_volunteer_type_repair', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $query = trim((string) $request->query->get('q', ''));

        $total = $this->users->countOnboardedWithoutVolunteerType($query);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $affected = $this->users->findOnboardedWithoutVolunteerType($query, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $rows = array_map(fn (User $user): array => [
            'user' => $user,
            'type' => $this->assigner->defaultFor($user),
        ], $affected);

        return $this->render('manage/volunteer_type/repair.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'q' => $query,
            'missingRoles' => $this->assigner->missingRoles(),
            'types' => $this->volunteerTypes->findAllOrdered(),
            'roles' => [VolunteerType::ROLE_VOLUNTEER, VolunteerType::ROLE_STAFF],
        ]);
    }

    /**
     * Claim an unclaimed role for an existing type, which is what makes onboarding work again.
     *
     * Repairing the users already onboarded is the smaller half of the job: without this every
     * user who finishes onboarding from now on lands in the same state.
     *
     * The previous holder gives the role up in its own flush. The column is unique and Doctrine may
     * order the two updates either way, so done in one the new claim can reach the database first
     * and the constraint rejects it.
     */
    #[Route('/role', name: 'app_manage_volunteer_type_repair_role', methods: ['POST'])]
    public function claimRole(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('volunteer-type-repair-role', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('common.flash.invalid_token'));

            return $this->redirectToRoute('app_manage_volunteer_type_repair');
        }

        $role = (string) $request->request->get('role', '');
        $type = $this->volunteerTypes->findOneByUuid((string) $request->request->get('type', ''));

        if (null === $type || !\in_array($role, [VolunteerType::ROLE_VOLUNTEER, VolunteerType::ROLE_STAFF], true)) {
            $this->addFlash('danger', new TranslatableMessage('manage.volunteer_type.repair.flash.role_invalid'));

            return $this->redirectToRoute('app_manage_volunteer_type_repair');
        }

        $existing = $this->volunteerTypes->findOneByRole($role);
        if (null !== $existing && $existing->getId() !== $type->getId()) {
            $existing->setRole(null);
            $this->em->flush();
        }

        $type->setRole($role);
        $this->em->flush();

        $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::UPDATE, [
            'details' => ['volunteer_type_role' => $role, 'type' => (string) $type->getUuid()],
        ]);
        $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.repair.flash.role_claimed', [
            '%type%' => $type->getName(),
            '%role%' => $role,
        ]));

        return $this->redirectToRoute('app_manage_volunteer_type_repair');
    }

    /**
     * Assign the base type to one user, the selected users, or everyone still affected.
     *
     * "Everyone" re-reads the affected set rather than trusting a count rendered earlier, so a
     * user repaired in another tab is not counted twice and one signing up in between is included.
     */
    #[Route('/apply', name: 'app_manage_volunteer_type_repair_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('volunteer-type-repair-apply', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('common.flash.invalid_token'));

            return $this->redirectToRoute('app_manage_volunteer_type_repair');
        }

        $scope = (string) $request->request->get('scope', 'selected');
        $targets = 'all' === $scope
            ? $this->allAffected()
            : $this->byUuids((array) $request->request->all('users'));

        $repaired = 0;
        $skipped = 0;
        foreach ($targets as $user) {
            if (null === $this->assigner->assign($user)) {
                ++$skipped;
                continue;
            }
            ++$repaired;
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
                'resourceType' => 'User',
                'resourceId' => $user->getId(),
                'details' => ['volunteer_type' => 'default assigned by repair'],
            ]);
        }
        $this->em->flush();

        if ($repaired > 0) {
            $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.repair.flash.repaired', ['%count%' => $repaired]));
        }
        if ($skipped > 0) {
            $this->addFlash('warning', new TranslatableMessage('manage.volunteer_type.repair.flash.skipped', ['%count%' => $skipped]));
        }

        return $this->redirectToRoute('app_manage_volunteer_type_repair', ['q' => (string) $request->request->get('q', '')]);
    }

    /**
     * Every affected user, read in pages so a large backlog does not hydrate at once.
     *
     * @return User[]
     */
    private function allAffected(): array
    {
        $all = [];
        $offset = 0;
        while (true) {
            $batch = $this->users->findOnboardedWithoutVolunteerType('', self::PER_PAGE, $offset);
            if ([] === $batch) {
                return $all;
            }
            foreach ($batch as $user) {
                $all[] = $user;
            }
            $offset += self::PER_PAGE;
        }
    }

    /**
     * @param list<mixed> $uuids
     *
     * @return User[]
     */
    private function byUuids(array $uuids): array
    {
        $users = [];
        foreach ($uuids as $uuid) {
            if (\is_string($uuid) && null !== $user = $this->users->findOneByUuid($uuid)) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
