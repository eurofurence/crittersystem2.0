<?php

namespace App\Tests\Integration;

use App\Tests\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:audit:health is the alarm for a stalled audit queue: the application keeps serving and keeps
 * dispatching audit events even when nothing consumes them, so the failure is otherwise invisible.
 *
 * A non-zero exit must therefore stay reachable - an alarm that cannot fire is not an alarm.
 */
final class AuditHealthCommandTest extends DatabaseTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find('app:audit:health'));
    }

    private function queueMessage(string $createdAt): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $db->insert('messenger_messages', [
            'body' => 'O:8:"stdClass":0:{}',
            'headers' => '[]',
            'queue_name' => 'default',
            'created_at' => $createdAt,
            'available_at' => $createdAt,
        ]);
    }

    public function testItPassesWhenTheQueueIsDraining(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('the trail is being written', $tester->getDisplay());
    }

    /** A single message older than the 15-minute limit already means the queue is not draining. */
    public function testItFailsWhenMessagesSitUnconsumed(): void
    {
        $this->queueMessage((new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s'));

        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]), 'a stalled audit queue MUST exit non-zero, or nothing will page anyone');
        self::assertStringContainsString('NOT being written', $tester->getDisplay());
        self::assertStringContainsString('messenger:consume', $tester->getDisplay(), 'the error must say how to fix it');
    }

    public function testItFailsWhenTheBacklogIsTooLarge(): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        for ($i = 0; $i < 6; ++$i) {
            $this->queueMessage($now);
        }

        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['--max-backlog' => '5']));
        self::assertStringContainsString('not keeping up', $tester->getDisplay());
    }
}
