<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unauthenticated one-click unsubscribe from non-critical emails. The token
 * identifies the recipient; a GET shows a confirmation and a POST performs the
 * change (so email link prefetching cannot silently unsubscribe anyone).
 * Critical notifications cannot be disabled.
 */
final class UnsubscribeController extends AbstractController
{
    private const TYPES = [
        'news' => 'News announcements',
        'shifts' => 'Shift notifications',
        'goodies' => 'Goodies notifications',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/unsubscribe/{token}', name: 'app_unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(string $token, Request $request): Response
    {
        $user = $this->users->findOneBy(['unsubscribeToken' => $token]);
        $type = (string) $request->query->get('type', $request->request->get('type', ''));
        if ($user === null || !\array_key_exists($type, self::TYPES)) {
            throw $this->createNotFoundException();
        }

        $done = false;
        if ($request->isMethod('POST')) {
            $settings = $user->getSettings();
            if ($settings !== null) {
                match ($type) {
                    'news' => $settings->setEmailNews(false),
                    'shifts' => $settings->setEmailShiftinfo(false),
                    'goodies' => $settings->setEmailGoodie(false),
                };
                $this->em->flush();
            }
            $done = true;
        }

        return $this->render('unsubscribe/confirm.html.twig', [
            'label' => self::TYPES[$type],
            'type' => $type,
            'token' => $token,
            'done' => $done,
        ]);
    }
}
