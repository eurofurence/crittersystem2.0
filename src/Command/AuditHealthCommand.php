<?php

namespace App\Command;

use App\Repository\AuditEventRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Is the audit trail actually being written?
 *
 * Audit events are dispatched to the async Messenger transport and written by a worker
 * (`messenger:consume async`). That is deliberate - the write stays off the request path - and the
 * doctrine transport is a durable buffer, so a worker restart loses nothing.
 *
 * The danger is not the queue breaking. It is the queue quietly ceasing to drain: with no worker
 * deployed the application keeps serving, every action still dispatches, and nothing is ever
 * recorded - without a single symptom.
 *
 * So: run this on a schedule and alert on a non-zero exit.
 *
 *   php bin/console app:audit:health
 *
 * Exit codes: 0 = healthy, 1 = degraded (thresholds exceeded, or messages in the failed transport).
 */
#[AsCommand(
    name: 'app:audit:health',
    description: 'Check that queued audit events are being consumed. Non-zero exit = the audit trail is not being written.',
)]
final class AuditHealthCommand extends Command
{
    /** A backlog this size means the worker is not keeping up (or is not running at all). */
    private const DEFAULT_MAX_BACKLOG = 500;

    /** No message should sit unconsumed longer than this. */
    private const DEFAULT_MAX_AGE_MINUTES = 15;

    public function __construct(
        private readonly Connection $db,
        private readonly AuditEventRepository $events,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-backlog', null, InputOption::VALUE_REQUIRED, 'Queued messages before the check fails.', (string) self::DEFAULT_MAX_BACKLOG)
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Minutes the oldest unconsumed message may reach.', (string) self::DEFAULT_MAX_AGE_MINUTES);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxBacklog = (int) $input->getOption('max-backlog');
        $maxAgeMinutes = (int) $input->getOption('max-age');

        $queued = (int) $this->db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name <> 'failed'");
        $failed = (int) $this->db->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed'");
        $oldest = $this->db->fetchOne("SELECT min(created_at) FROM messenger_messages WHERE queue_name <> 'failed'");
        $auditRows = $this->events->countAll();

        $oldestAgeMinutes = null;
        if (\is_string($oldest) && $oldest !== '') {
            $oldestAt = new \DateTimeImmutable($oldest);
            $oldestAgeMinutes = (int) floor((time() - $oldestAt->getTimestamp()) / 60);
        }

        $problems = [];
        if ($queued > $maxBacklog) {
            $problems[] = \sprintf('%d messages queued (limit %d) - the worker is not keeping up, or is not running.', $queued, $maxBacklog);
        }
        if ($oldestAgeMinutes !== null && $oldestAgeMinutes > $maxAgeMinutes) {
            $problems[] = \sprintf('the oldest unconsumed message is %d minutes old (limit %d) - the queue is not draining. Is `messenger:consume async` running?', $oldestAgeMinutes, $maxAgeMinutes);
        }
        if ($failed > 0) {
            $problems[] = \sprintf('%d message(s) in the failed transport - inspect with `messenger:failed:show`, retry with `messenger:failed:retry`.', $failed);
        }

        $io->definitionList(
            ['audit_events rows' => (string) $auditRows],
            ['queued (unconsumed)' => (string) $queued],
            ['oldest unconsumed' => $oldestAgeMinutes === null ? '-' : $oldestAgeMinutes.' min'],
            ['failed transport' => (string) $failed],
        );

        if ($problems !== []) {
            $io->error('The audit trail is NOT being written reliably.');
            foreach ($problems as $problem) {
                $io->writeln(' - '.$problem);
            }

            return Command::FAILURE;
        }

        $io->success('Audit queue is draining; the trail is being written.');

        return Command::SUCCESS;
    }
}
