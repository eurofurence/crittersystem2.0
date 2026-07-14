<?php

namespace App\Service;

use App\Entity\Message;
use App\Entity\News;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends event email notifications respecting per-user preferences.
 * The dev mailer DSN is null://null, so sends are no-ops until SMTP is set.
 *
 * Shift-related notifications are deliberately not routed through here.
 */
final class Notifier
{
    private const FROM = 'noreply@critter.example';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $users,
        private readonly ?UnsubscribeLinker $unsubscribe = null,
    ) {
    }

    /** Email news to subscribers (email_news), honouring staff-only visibility. Returns count sent. */
    public function newsPublished(News $news): int
    {
        $sent = 0;
        foreach ($this->users->findSubscribedToNews() as $user) {
            if ($news->isStaffOnly() && !$user->isStaff()) {
                continue;
            }
            // Non-critical email: always include an unsubscribe link.
            $this->send($user->getEmail(), 'News: '.$news->getTitle(), $news->getPreview(), $user, 'news');
            ++$sent;
        }

        return $sent;
    }

    /** Email the recipient about a new private message if they enabled email_messages. */
    public function messageSent(Message $message): bool
    {
        $receiver = $message->getReceiver();
        if ($receiver->getSettings()?->isEmailMessages() !== true) {
            return false;
        }

        $this->send(
            $receiver->getEmail(),
            'New message from '.$message->getSender()->getName(),
            $message->getText(),
        );

        return true;
    }

    /** Generic notification email to a user (used by the notification centre). */
    public function sendTo(User $user, string $subject, string $body): void
    {
        $this->send($user->getEmail(), $subject, $body);
    }

    private function send(string $to, string $subject, string $body, ?User $user = null, ?string $unsubscribeType = null): void
    {
        if ($user !== null && $unsubscribeType !== null && $this->unsubscribe !== null) {
            $body .= "\n\n—\nUnsubscribe from these emails: ".$this->unsubscribe->url($user, $unsubscribeType);
        }

        $this->mailer->send(
            (new Email())->from(self::FROM)->to($to)->subject($subject)->text($body),
        );
    }
}
