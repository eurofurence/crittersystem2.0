<?php

namespace App\Controller;

use App\Entity\User;
use App\Notification\NotificationCategories;
use App\Repository\NotificationRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * In-app notification centre: bell fragment, history page and
 * the per-user channel×category preference matrix.
 */
#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
final class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_notifications', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('notifications/index.html.twig', [
            'notifications' => $this->notifications->history($this->currentUser()),
        ]);
    }

    #[Route('/bell', name: 'app_notifications_bell', methods: ['GET'])]
    public function bell(): Response
    {
        return $this->render('notifications/_bell_content.html.twig', [
            'vm' => $this->bellModel(),
        ]);
    }

    #[Route('/read-all', name: 'app_notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        if ($this->isCsrfTokenValid('notif', (string) $request->request->get('_notif_token'))) {
            $this->notifications->markAllRead($this->currentUser());
        }

        return $this->render('notifications/_bell.html.twig', ['vm' => $this->bellModel()]);
    }

    #[Route('/open/{id}', name: 'app_notifications_open', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function open(string $id): Response
    {
        $notification = $this->repository->findOneBy(['uuid' => $id]);
        if ($notification === null || $notification->getUser() !== $this->currentUser()) {
            throw $this->createNotFoundException();
        }

        $this->notifications->markRead($notification);

        return $this->redirect($notification->getActionUrl() ?? $this->generateUrl('app_notifications'));
    }

    #[Route('/preferences', name: 'app_notifications_preferences', methods: ['GET', 'POST'])]
    public function preferences(Request $request): Response
    {
        $user = $this->currentUser();

        if ($request->isMethod('POST') && $this->isCsrfTokenValid('notif_prefs', (string) $request->request->get('_token'))) {
            /** @var array<string, array{inApp?: string, email?: string, telegram?: string}> $input */
            $input = $request->request->all('pref');
            $this->notifications->savePreferences($user, $input);

            $lead = $request->request->get('reminder_lead');
            $user->getSettings()?->setNotificationReminderLead($lead === '' || $lead === null ? null : (int) $lead);
            $this->em->flush();

            $this->addFlash('success', 'Notification preferences saved.');

            return $this->redirectToRoute('app_notifications_preferences');
        }

        return $this->render('notifications/preferences.html.twig', [
            'matrix' => $this->notifications->preferenceMatrix($user),
            'reminderLead' => $user->getSettings()?->getNotificationReminderLead(),
            'reminderChoices' => [15, 30, 60, 120],
        ]);
    }

    /**
     * @return array{count: int, recent: array<int, \App\Entity\Notification>}
     */
    private function bellModel(): array
    {
        $user = $this->currentUser();

        return [
            'count' => $this->notifications->unreadCount($user),
            'recent' => $this->notifications->recent($user, 8),
        ];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
