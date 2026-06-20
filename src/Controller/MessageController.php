<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One-to-one private messaging: an inbox of conversations and a
 * per-correspondent thread with read tracking.
 */
#[Route('/messages')]
#[IsGranted('user_messages')]
final class MessageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRepository $messages,
        private readonly UserRepository $users,
        private readonly Notifier $notifier,
    ) {
    }

    #[Route('', name: 'app_messages_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        $conversations = [];
        foreach ($this->messages->findInvolving($me) as $message) {
            $other = $message->getSender() === $me ? $message->getReceiver() : $message->getSender();
            $oid = $other->getId();
            if (!isset($conversations[$oid])) {
                $conversations[$oid] = ['user' => $other, 'last' => $message, 'unread' => 0];
            }
            if ($message->getReceiver() === $me && !$message->isRead()) {
                ++$conversations[$oid]['unread'];
            }
        }

        $q = trim((string) $request->query->get('q', ''));

        return $this->render('message/index.html.twig', [
            'conversations' => $conversations,
            'q' => $q,
            'searchResults' => $q !== '' ? array_filter($this->users->search($q), fn (User $u) => $u !== $me) : [],
        ]);
    }

    #[Route('/{id}', name: 'app_messages_conversation', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function conversation(User $other): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        if ($other === $me) {
            throw $this->createNotFoundException();
        }

        $this->messages->markConversationRead($me, $other);

        return $this->render('message/conversation.html.twig', [
            'other' => $other,
            'messages' => $this->messages->findConversation($me, $other),
        ]);
    }

    #[Route('/{id}', name: 'app_messages_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(Request $request, User $other): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        $text = trim((string) $request->request->get('text'));

        if ($other !== $me
            && $text !== ''
            && $this->isCsrfTokenValid('message'.$other->getId(), (string) $request->request->get('_token'))
        ) {
            $message = new Message($me, $other, $text);
            $this->em->persist($message);
            $this->em->flush();
            $this->notifier->messageSent($message);
            $this->addFlash('success', 'Message sent.');
        }

        return $this->redirectToRoute('app_messages_conversation', ['id' => $other->getId()]);
    }
}
