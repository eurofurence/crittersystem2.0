<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\ErasureRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles the "right to be forgotten" flow: emailing the final confirmation link
 * and executing the irreversible deletion. On execution the person is added to
 * the hashed ban list and the whole process is audited (audit entries are never
 * erased).
 */
final class ErasureService
{
    private const FROM = 'noreply@critter.example';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BanChecker $bans,
        private readonly AuditLogger $audit,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function request(User $user): ErasureRequest
    {
        $request = new ErasureRequest($user, bin2hex(random_bytes(24)));
        $this->em->persist($request);
        $this->em->flush();

        $this->audit->log(AuditEvents::GDPR, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['erasure' => 'requested'],
        ]);

        $url = $this->urlGenerator->generate('app_erase_confirm', ['token' => $request->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send(
            (new Email())->from(self::FROM)->to($user->getEmail())
                ->subject('Confirm account deletion')
                ->text("You requested permanent deletion of your account. This is irreversible.\n\n"
                    ."Confirm within 6 hours:\n".$url."\n\nIf you did not request this, ignore this email."),
        );

        return $request;
    }

    /**
     * Permanently delete the account and add it to the ban list.
     *
     * The audit entry is written before the removal, because it outlives the account.
     */
    public function execute(ErasureRequest $request): void
    {
        $user = $request->getUser();

        $this->audit->log(AuditEvents::GDPR, AuditEvents::DELETE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['erasure' => 'executed', 'username' => $user->getUserIdentifier()],
        ]);

        $this->bans->ban($user);
        $this->em->remove($request);
        $this->em->remove($user);
        $this->em->flush();
    }
}
