<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Repository\ConversationRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ConversationService;
use App\Service\Chat\InfoDeskQueueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Internal messaging and Info Desk chat, on the conversation model.
 * Every user sees the Info Desk Team as their default contact; Info Desk members
 * get waiting/claimed queues. Messages poll for near-real-time updates.
 */
#[Route('/messages')]
#[IsGranted('message:use')]
final class MessageController extends AbstractController
{
    public function __construct(
        private readonly ConversationService $chat,
        private readonly InfoDeskQueueService $queue,
        private readonly ConversationRepository $conversations,
    ) {
    }

    #[Route('', name: 'app_messages_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        if (!$this->chat->enabled()) {
            return $this->render('message/disabled.html.twig');
        }

        $mine = [];
        foreach ($this->conversations->findForParticipant($me) as $conversation) {
            $mine[] = ['conversation' => $conversation, 'unread' => $this->chat->unreadCount($conversation, $me)];
        }

        $canClaim = $this->isGranted('chat:claim');

        return $this->render('message/index.html.twig', [
            'support' => $this->chat->startSupport($me),
            'mine' => $mine,
            'canClaim' => $canClaim,
            'waiting' => $canClaim ? $this->queue->waiting() : [],
            'claimed' => $canClaim ? $this->queue->claimedBy($me) : [],
            'queueService' => $this->queue,
        ]);
    }

    /**
     * The Info Desk queue, on its own so the live region can re-fetch just that part.
     *
     * Gated by chat:claim, the same privilege that puts the queue topic in a subscriber token. The
     * fragment is rendered per viewer - "claimed by you" is not the same list for two responders -
     * which is why the queue signal carries no markup.
     */
    #[Route('/queue/frame', name: 'app_messages_queue_frame', methods: ['GET'])]
    #[IsGranted('chat:claim')]
    public function queueFrame(): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        return $this->render('message/_queue.html.twig', [
            'waiting' => $this->queue->waiting(),
            'claimed' => $this->queue->claimedBy($me),
        ]);
    }

    #[Route('/info-desk', name: 'app_messages_infodesk', methods: ['GET'])]
    public function infoDesk(): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        // Predefined contacts reuse the messaging infrastructure: a
        // contact-Info-Desk action opens the shared support conversation, which
        // shows the configured welcome message on first contact.
        $conversation = $this->chat->startSupport($me);

        return $this->redirectToRoute('app_messages_conversation', ['id' => $conversation->getUuid()]);
    }

    #[Route('/with/{id}', name: 'app_messages_with', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function with(#[MapEntity(mapping: ['id' => 'uuid'])] User $target): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        try {
            $conversation = $this->chat->startDirect($me, $target);
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_messages_index');
        }

        return $this->redirectToRoute('app_messages_conversation', ['id' => $conversation->getUuid()]);
    }

    #[Route('/{id}', name: 'app_messages_conversation', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function conversation(#[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation): Response
    {
        $me = $this->requireParticipant($conversation);
        $this->chat->markRead($conversation, $me);

        return $this->render('message/conversation.html.twig', [
            'conversation' => $conversation,
            'messages' => $this->chat->visibleMessages($conversation, $me),
            'chat' => $this->chat,
            'queueService' => $this->queue,
            'canClaim' => $this->isGranted('chat:claim'),
        ]);
    }

    #[Route('/{id}/frame', name: 'app_messages_frame', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function frame(#[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation): Response
    {
        $me = $this->requireParticipant($conversation);
        $this->chat->markRead($conversation, $me);

        return $this->render('message/_thread.html.twig', [
            'conversation' => $conversation,
            'messages' => $this->chat->visibleMessages($conversation, $me),
            'chat' => $this->chat,
            'typing' => $this->chat->othersTyping($conversation, $me),
        ]);
    }

    #[Route('/{id}/send', name: 'app_messages_send', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function send(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation): Response
    {
        $me = $this->requireParticipant($conversation);
        if ($conversation->isOpen()
            && ($text = trim((string) $request->request->get('text'))) !== ''
            && $this->isCsrfTokenValid('chat'.$conversation->getId(), (string) $request->request->get('_token'))) {
            // Only Info Desk / Admins may send links.
            $error = $this->chat->restrictedContentError($text, $this->isGranted('chat:restricted'));
            if ($error !== null) {
                $this->addFlash('danger', $error);
            } else {
                $this->chat->post($conversation, $me, $text);
            }
        }

        return $this->redirectToRoute('app_messages_conversation', ['id' => $conversation->getUuid()]);
    }

    #[Route('/message/{id}/edit', name: 'app_messages_edit', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] \App\Entity\ChatMessage $message): Response
    {
        $conversation = $message->getConversation();
        $me = $this->requireParticipant($conversation);
        if ($this->isCsrfTokenValid('chat_edit'.$message->getId(), (string) $request->request->get('_token'))) {
            try {
                $this->chat->editMessage($message, $me, trim((string) $request->request->get('text')), $this->isGranted('chat:restricted'));
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_messages_conversation', ['id' => $conversation->getUuid()]);
    }

    #[Route('/{id}/image', name: 'app_messages_image', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('chat:restricted')]
    public function image(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation, \App\Service\Chat\ChatImageStore $images): Response
    {
        $me = $this->requireParticipant($conversation);
        $file = $request->files->get('image');
        if ($conversation->isOpen() && $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            && $this->isCsrfTokenValid('chat'.$conversation->getId(), (string) $request->request->get('_token'))) {
            try {
                $this->chat->postImage($conversation, $me, $images->store($file));
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_messages_conversation', ['id' => $conversation->getUuid()]);
    }

    #[Route('/{id}/typing', name: 'app_messages_typing', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function typing(#[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation): Response
    {
        $me = $this->requireParticipant($conversation);
        $this->chat->markTyping($conversation, $me);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/{id}/claim', name: 'app_messages_claim', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('chat:claim')]
    public function claim(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        if ($this->isCsrfTokenValid('chat_claim'.$conversation->getId(), (string) $request->request->get('_token'))) {
            try {
                $exclusive = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUBADMIN');
                $this->queue->claim($conversation, $me, $exclusive);
            } catch (\RuntimeException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_messages_conversation', ['id' => $conversation->getUuid()]);
    }

    #[Route('/{id}/unclaim', name: 'app_messages_unclaim', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('chat:claim')]
    public function unclaim(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        if ($this->isCsrfTokenValid('chat_claim'.$conversation->getId(), (string) $request->request->get('_token'))) {
            $this->queue->unclaim($conversation, $me);
        }

        return $this->redirectToRoute('app_messages_index');
    }

    #[Route('/{id}/close', name: 'app_messages_close', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('chat:claim')]
    public function close(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Conversation $conversation): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        if ($this->isCsrfTokenValid('chat_claim'.$conversation->getId(), (string) $request->request->get('_token'))) {
            $this->queue->close($conversation, $me);
        }

        return $this->redirectToRoute('app_messages_index');
    }

    /**
     * The rule itself lives in {@see ConversationService::mayParticipate()}, because the Mercure
     * topic builder decides from the same predicate whether this conversation's live updates may
     * reach a user. Two copies would eventually mean a thread that pushes to someone this method
     * would turn away.
     */
    private function requireParticipant(Conversation $conversation): User
    {
        /** @var User $me */
        $me = $this->getUser();
        if (!$this->chat->mayParticipate($conversation, $me)) {
            throw $this->createAccessDeniedException();
        }

        return $me;
    }
}
