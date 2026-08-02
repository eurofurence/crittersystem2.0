<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes support conversations that hold no messages at all.
 *
 * Repairs the wreckage of a defect: the messages list used to open a support conversation merely by
 * being rendered, so every volunteer who visited /messages queued one for the Info Desk to work
 * through. Those conversations contain nothing - not even the welcome message when none is
 * configured - and are indistinguishable from work.
 *
 * Reports by default and only deletes with --force, because it removes rows a person might be about
 * to write into. The age guard exists for the same reason: opening the conversation is still a
 * legitimate first step, so one created moments ago may simply be waiting for its author to finish
 * typing. Default 24 hours.
 */
#[AsCommand(
    name: 'app:chat:purge-empty-support',
    description: 'Remove support conversations that contain no messages.',
)]
final class PurgeEmptySupportConversationsCommand extends Command
{
    private const DEFAULT_MIN_AGE_HOURS = 24;

    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete; without this the command only reports.')
            ->addOption(
                'min-age-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Only consider conversations older than this, so one being written right now is left alone.',
                (string) self::DEFAULT_MIN_AGE_HOURS,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $minAgeHours = max(0, (int) $input->getOption('min-age-hours'));
        $before = new \DateTimeImmutable(\sprintf('-%d hours', $minAgeHours));

        $empty = $this->conversations->findEmptySupportCreatedBefore($before);

        if ($empty === []) {
            $io->success('No empty support conversations.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($empty as $conversation) {
            $rows[] = [
                (string) $conversation->getUuid(),
                $conversation->getSubject()?->getName() ?? '-',
                $conversation->getStatus()->value,
                $conversation->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }
        $io->table(['Conversation', 'Subject', 'Status', 'Created'], $rows);

        if (!$force) {
            $io->warning(\sprintf(
                '%d empty support conversation(s) would be removed. Re-run with --force to delete them.',
                \count($empty),
            ));

            return Command::SUCCESS;
        }

        foreach ($empty as $conversation) {
            // Audited individually: this deletes a record a volunteer's name is attached to, and the
            // trail is what makes that answerable afterwards.
            $this->audit->system(AuditEvents::CHAT, AuditEvents::DELETE, [
                'resourceType' => 'conversation',
                'resourceId' => (string) $conversation->getUuid(),
                'resourceOwnerId' => $conversation->getSubject()?->getId(),
                'details' => ['reason' => 'empty support conversation', 'created_at' => $conversation->getCreatedAt()->format(\DATE_ATOM)],
            ]);

            $this->em->remove($conversation);
        }
        $this->em->flush();

        $io->success(\sprintf('Removed %d empty support conversation(s).', \count($empty)));

        return Command::SUCCESS;
    }
}
