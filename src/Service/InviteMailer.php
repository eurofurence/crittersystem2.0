<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InviteToken;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Sends the account-invitation email with the one-time confirmation link. */
final class InviteMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function send(InviteToken $invite): void
    {
        $url = $this->urlGenerator->generate('app_invite_accept', ['token' => $invite->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $user = $invite->getUser();

        $body = "You have been invited to the volunteer system.\n\n"
            ."Confirm your account and set a password (link valid for ".InviteToken::TTL_HOURS." hours):\n"
            .$url."\n\nIf you did not expect this, you can ignore this email.";

        $this->mailer->send(
            (new Email())->to($user->getEmail())
                ->subject('Your volunteer account invitation')->text($body),
        );
    }
}
