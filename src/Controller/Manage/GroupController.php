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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
            $this->addFlash('success', \sprintf('Group "%s" created.', $group->getName()));

            return $this->redirectToRoute('app_manage_group_index');
        }

        return $this->render('manage/group/form.html.twig', [
            'form' => $form,
            'group' => $group,
            'heading' => 'New group',
            'permissionMeta' => $this->permissionMeta(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_group_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('rbac:group:manage')]
    public function edit(Request $request, Group $group): Response
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
            $this->addFlash('success', \sprintf('Group "%s" updated.', $group->getName()));

            return $this->redirectToRoute('app_manage_group_index');
        }

        return $this->render('manage/group/form.html.twig', [
            'form' => $form,
            'group' => $group,
            'heading' => 'Edit group',
            'permissionMeta' => $this->permissionMeta(),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_group_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('rbac:group:manage')]
    public function delete(Request $request, Group $group): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$group->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_group_index');
        }

        if (\array_key_exists($group->getSlug(), PrivilegeCatalog::GROUPS)) {
            $this->addFlash('danger', 'Core groups cannot be deleted.');

            return $this->redirectToRoute('app_manage_group_index');
        }

        if (!$group->getAssignments()->isEmpty()) {
            $this->addFlash('danger', 'This group still has members. Remove them before deleting it.');

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
        $this->addFlash('success', 'Group deleted.');

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
