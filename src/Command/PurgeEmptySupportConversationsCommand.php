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
 * An empty conversation holds nothing, not even the welcome message when none is configured, so the
 * Info Desk cannot tell it apart from real work.
 *
 * Reports by default and only deletes with --force, because it removes rows a person might be about
 * to write into. The age guard exists for the same reason: opening the conversation is a legitimate
 * first step, so one created moments ago may simply be waiting for its author to finish typing.
 * Default 24 hours.
 *
 * Every deletion is audited on its own: the record carries a volunteer's name, and the trail is what
 * makes the removal answerable afterwards.
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
