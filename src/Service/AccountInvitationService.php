<?php

namespace App\Service;

use App\Entity\InviteToken;
use App\Entity\User;
use App\Repository\InviteTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AccountInvitationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InviteTokenRepository $invites,
        private readonly InviteMailer $mailer,
    ) {
    }

    public function reissue(User $user): InviteToken
    {
        $token = bin2hex(random_bytes(24));
        $invite = $this->invites->findOneByUser($user);
        if ($invite === null) {
            $invite = new InviteToken($user, $token);
            $this->em->persist($invite);
        } else {
            $invite->renew($token);
        }

        $this->em->flush();
        $this->mailer->send($invite);

        return $invite;
    }
}
