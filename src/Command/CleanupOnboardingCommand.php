<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Repository\InviteTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Removes invited accounts that never completed onboarding within 24 hours and
 * were never used (no login). A renewed invitation extends that lifetime until
 * its current token expires. Each removal is audited and the site admins are
 * notified. Established accounts (any elevated role, or that have logged in) are
 * never touched. Intended to run on a schedule.
 */
#[AsCommand(
    name: 'app:onboarding:cleanup',
    description: 'Remove stale, never-used accounts that did not complete onboarding.',
)]
final class CleanupOnboardingCommand extends Command
{
    private const MAX_AGE_HOURS = 24;

    public function __construct(
        private readonly UserRepository $users,
        private readonly InviteTokenRepository $invites,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly MailerInterface $mailer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cutoff = new \DateTimeImmutable('-'.self::MAX_AGE_HOURS.' hours');
        $now = new \DateTimeImmutable();

        $removed = [];
        foreach ($this->users->findStaleIncompleteOnboarding($cutoff) as $user) {
            // Never remove privileged accounts, even if not onboarded.
            if ($user->getRoles() !== ['ROLE_USER']) {
                continue;
            }
            // A resend deliberately gives the user a fresh full TTL even when the
            // account itself is older than the original 24-hour cleanup cutoff.
            $invite = $this->invites->findOneByUser($user);
            if ($invite !== null && !$invite->isExpired($now)) {
                continue;
            }

            $removed[] = $user->getUserIdentifier();
            $this->audit->system(AuditEvents::USER_MANAGEMENT, AuditEvents::DELETE, [
                'resourceType' => 'User',
                'resourceId' => $user->getId(),
                'details' => ['reason' => 'onboarding_not_completed', 'username' => $user->getUserIdentifier()],
            ]);
            $this->em->remove($user);
        }
        $this->em->flush();

        if ($removed !== []) {
            $this->notifyAdmins($removed);
        }

        $io->success(\sprintf('Removed %d stale onboarding account(s).', \count($removed)));

        return Command::SUCCESS;
    }

    /** @param string[] $usernames */
    private function notifyAdmins(array $usernames): void
    {
        $admins = $this->users->findByGroupRole('ROLE_ADMIN');
        if ($admins === []) {
            return;
        }

        $body = "The following never-used accounts were removed for not completing onboarding within "
            .self::MAX_AGE_HOURS." hours:\n\n - ".implode("\n - ", $usernames);

        foreach ($admins as $admin) {
            $this->mailer->send(
                (new Email())
                    ->to($admin->getEmail())
                    ->subject('Stale onboarding accounts removed')
                    ->text($body),
            );
        }
    }
}
