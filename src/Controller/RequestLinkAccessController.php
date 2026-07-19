<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\RequestLinkType;
use App\Service\Invite\RequestLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public entry for a department request/invitation link. Login
 * is required; an expired or revoked link is refused. On a valid use the link is
 * recorded (and may auto-add the user to a non-SSO department per the global
 * toggle), then the user is routed to the availability grid or the department's
 * filtered shift application view.
 */
#[IsGranted('ROLE_USER')]
final class RequestLinkAccessController extends AbstractController
{
    public function __construct(private readonly RequestLinkService $service)
    {
    }

    #[Route('/link/{token}', name: 'app_request_link_access', methods: ['GET'], requirements: ['token' => '[a-f0-9]{48}'])]
    public function access(string $token): Response
    {
        $link = $this->service->findActiveByToken($token);
        if ($link === null) {
            return $this->render('request_link/invalid.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        /** @var User $user */
        $user = $this->getUser();
        $joined = $this->service->use($link, $user);
        if ($joined) {
            $this->addFlash('success', new TranslatableMessage('request_link.flash.added', ['%name%' => $link->getDepartment()->getName()]));
        }

        if ($link->getType() === RequestLinkType::AVAILABILITY_REQUEST) {
            $this->addFlash('info', new TranslatableMessage('request_link.flash.availability_requested', ['%name%' => $link->getDepartment()->getName()]));

            return $this->redirectToRoute('app_availability');
        }

        return $this->redirectToRoute('app_manage_shifts_apply', ['department' => $link->getDepartment()->getUuid()]);
    }
}
