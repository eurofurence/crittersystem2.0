<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Group;
use App\Form\GroupType;
use App\Repository\GroupRepository;
use App\Security\PrivilegeCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Administration of permission groups: which permissions and coarse role each
 * group grants. Viewing is open to anyone with rbac:group:view; any change
 * requires the admin-only, step-up-protected rbac:group:manage.
 */
#[Route('/manage/groups')]
#[IsGranted('rbac:group:view')]
final class GroupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GroupRepository $groups,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return string[] */
    private function permissionNames(Group $group): array
    {
        return array_map(static fn ($p) => $p->getName(), $group->getPrivileges()->toArray());
    }

    #[Route('', name: 'app_manage_group_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/group/index.html.twig', [
            'groups' => $this->groups->findBy([], ['name' => 'ASC']),
            'core_group_slugs' => array_keys(PrivilegeCatalog::GROUPS),
        ]);
    }

    /**
     * Every group against every permission at once.
     *
     * The per-group edit screen answers "what does this group grant"; the question an administrator
     * actually has when reviewing access is the other one, "who grants this", and answering it by
     * opening a dozen groups in turn is how a stray grant goes unnoticed. Rows are grouped by the
     * same categories the edit screen uses so the two read alike.
     */
    #[Route('/matrix', name: 'app_manage_group_matrix', methods: ['GET'])]
    public function matrix(Request $request): Response
    {
        $all = $this->groups->findBy([], ['name' => 'ASC']);
        $shown = $this->pickedGroups($all, $request);

        return $this->render('manage/group/matrix.html.twig', [
            'groups' => $shown,
            'picker' => array_map(
                static fn (Group $group): array => ['group' => $group, 'selected' => \in_array($group, $shown, true)],
                $all,
            ),
            'categories' => $this->matrixCategories($shown),
            'core_group_slugs' => array_keys(PrivilegeCatalog::GROUPS),
        ]);
    }

    /**
     * The groups to draw columns for, from `?groups[]=<uuid>`.
     *
     * No selection means all of them, which is also what an empty one means: a matrix with no
     * columns answers nothing, so unticking the last group returns the full picture rather than an
     * empty screen. An unknown uuid is ignored rather than refused - the picker is a view filter,
     * and a stale link should still show something.
     *
     * @param Group[] $all
     *
     * @return list<Group>
     */
    private function pickedGroups(array $all, Request $request): array
    {
        $wanted = array_values(array_filter(
            $request->query->all('groups'),
            static fn (mixed $uuid): bool => \is_string($uuid) && $uuid !== '',
        ));
        if ($wanted === []) {
            return array_values($all);
        }

        $picked = array_values(array_filter(
            $all,
            static fn (Group $group): bool => \in_array((string) $group->getUuid(), $wanted, true),
        ));

        return $picked === [] ? array_values($all) : $picked;
    }

    /**
     * The matrix rows: every catalogued permission under its category, each carrying one flag per
     * group in the order the columns are drawn.
     *
     * A permission a group holds that the catalogue does not know about is listed too, under its
     * own heading. Dropping it would be the one kind of omission this screen must not make: a grant
     * nobody can see is a grant nobody reviews.
     *
     * @param Group[] $groups
     *
     * @return list<array{name: string, permissions: list<array{name: string, description: string, twoFactor: bool, held: list<bool>}>}>
     */
    private function matrixCategories(array $groups): array
    {
        $held = [];
        $uncatalogued = [];
        foreach ($groups as $index => $group) {
            foreach ($group->getPrivileges() as $privilege) {
                $name = $privilege->getName();
                $held[$name][$index] = true;
                if (!PrivilegeCatalog::isPrivilege($name)) {
                    $uncatalogued[$name] = true;
                }
            }
        }

        $byCategory = [];
        foreach (array_keys(PrivilegeCatalog::PERMISSIONS) as $name) {
            $byCategory[PrivilegeCatalog::category($name)][] = $name;
        }
        foreach (array_keys($uncatalogued) as $name) {
            $byCategory['Other'][] = $name;
        }

        $categories = [];
        foreach ($byCategory as $category => $names) {
            $permissions = [];
            foreach ($names as $name) {
                $permissions[] = [
                    'name' => $name,
                    'description' => PrivilegeCatalog::description($name),
                    'twoFactor' => PrivilegeCatalog::requiresTwoFactor($name),
                    'held' => array_map(
                        static fn (int $index): bool => isset($held[$name][$index]),
                        array_keys($groups),
                    ),
                ];
            }
            $categories[] = ['name' => $category, 'permissions' => $permissions];
        }

        return $categories;
    }

    #[Route('/new', name: 'app_manage_group_new', methods: ['GET', 'POST'])]
    #[IsGranted('rbac:group:manage')]
    public function new(Request $request): Response
    {
        $group = new Group('', '');
        $form = $this->createForm(GroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $group->setSlug($this->uniqueSlug($group->getName()));
            $this->em->persist($group);
            $this->em->flush();
            $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::CREATE, [
                'resourceType' => 'Group',
                'resourceId' => $group->getId(),
                'details' => ['name' => $group->getName(), 'role' => $group->getRole(), 'permissions' => $this->permissionNames($group)],
            ]);
            $this->addFlash('success', new TranslatableMessage('manage.group.flash.created', ['%name%' => $group->getName()]));

            return $this->redirectToRoute('app_manage_group_index');
        }

        return $this->render('manage/group/form.html.twig', [
            'form' => $form,
            'group' => $group,
            'heading' => 'manage.group.form.heading_new',
            'permissionMeta' => $this->permissionMeta(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_group_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('rbac:group:manage')]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Group $group): Response
    {
        $form = $this->createForm(GroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::UPDATE, [
                'resourceType' => 'Group',
                'resourceId' => $group->getId(),
                'details' => ['name' => $group->getName(), 'role' => $group->getRole(), 'permissions' => $this->permissionNames($group)],
            ]);
            $this->addFlash('success', new TranslatableMessage('manage.group.flash.updated', ['%name%' => $group->getName()]));

            return $this->redirectToRoute('app_manage_group_index');
        }

        return $this->render('manage/group/form.html.twig', [
            'form' => $form,
            'group' => $group,
            'heading' => 'manage.group.form.heading_edit',
            'permissionMeta' => $this->permissionMeta(),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_group_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('rbac:group:manage')]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Group $group): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$group->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_group_index');
        }

        if (\array_key_exists($group->getSlug(), PrivilegeCatalog::GROUPS)) {
            $this->addFlash('danger', new TranslatableMessage('manage.group.flash.core_delete_denied'));

            return $this->redirectToRoute('app_manage_group_index');
        }

        if (!$group->getAssignments()->isEmpty()) {
            $this->addFlash('danger', new TranslatableMessage('manage.group.flash.has_members'));

            return $this->redirectToRoute('app_manage_group_index');
        }

        $name = $group->getName();
        $id = $group->getId();
        $this->em->remove($group);
        $this->em->flush();
        $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::DELETE, [
            'resourceType' => 'Group',
            'resourceId' => $id,
            'details' => ['name' => $name],
        ]);
        $this->addFlash('success', new TranslatableMessage('manage.group.flash.deleted'));

        return $this->redirectToRoute('app_manage_group_index');
    }

    /**
     * Permission name => [category, description, twoFactor] for grouping the
     * checkboxes and flagging step-up permissions in the template.
     *
     * @return array<string, array{category: string, description: string, twoFactor: bool}>
     */
    private function permissionMeta(): array
    {
        $meta = [];
        foreach (PrivilegeCatalog::PERMISSIONS as $name => $_) {
            $meta[$name] = [
                'category' => PrivilegeCatalog::category($name),
                'description' => PrivilegeCatalog::description($name),
                'twoFactor' => PrivilegeCatalog::requiresTwoFactor($name),
            ];
        }

        return $meta;
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-') ?: 'group';
        $slug = $base;
        $n = 2;
        while ($this->groups->findOneBySlug($slug) !== null) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
