<?php

namespace App\Controller\Admin;

use App\Form\Model\SsoRoleData;
use App\Form\SsoRoleType;
use App\Sso\SsoRoleSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The identity-provider role IDs that decide a user's position inside every department they are
 * mapped into.
 *
 * The page shows raw IdP identifiers, so it is doubly gated and both gates are load-bearing:
 *  - `/admin` demands ROLE_ADMIN at the firewall, which keeps sub-admins out (they auto-hold only
 *    sub-admin-level privileges, and `config:sso` is admin-level);
 *  - `config:sso` is flagged for step-up in PrivilegeCatalog, so TwoFactorStepUpSubscriber requires
 *    2FA enrolment and a fresh confirmation before this controller runs.
 */
#[Route('/admin/sso-roles')]
#[IsGranted('config:sso')]
final class SsoRoleController extends AbstractController
{
    public function __construct(private readonly SsoRoleSettings $settings)
    {
    }

    #[Route('', name: 'app_admin_sso_roles', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $data = new SsoRoleData();
        $data->departmentManagerRole = $this->settings->departmentManagerRole();
        $data->shiftManagerRole = $this->settings->shiftManagerRole();

        $form = $this->createForm(SsoRoleType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->save($data->departmentManagerRole, $data->shiftManagerRole);
            $this->addFlash('success', 'SSO department roles saved. They apply on each user\'s next sign-in.');

            return $this->redirectToRoute('app_admin_sso_roles');
        }

        return $this->render('admin/sso/roles.html.twig', ['form' => $form]);
    }
}
