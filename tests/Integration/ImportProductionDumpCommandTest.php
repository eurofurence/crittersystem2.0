<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\ExecutableFinder;

/**
 * app:db:import-prod drops the database it is pointed at, so every reason to
 * refuse has to be found before that happens. These cases only exercise the
 * refusals - none of them may reach the restore.
 */
final class ImportProductionDumpCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();

        return new CommandTester((new Application(static::$kernel))->find('app:db:import-prod'));
    }

    public function testItRefusesWithoutASource(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('exactly one of', $tester->getDisplay());
    }

    public function testItRefusesTwoSourcesAtOnce(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => 'some.dump', '--from-s3' => true]));
        self::assertStringContainsString('exactly one of', $tester->getDisplay());
    }

    public function testItRefusesADumpThatIsNotThere(): void
    {
        if ((new ExecutableFinder())->find('pg_restore') === null) {
            self::markTestSkipped('No pg_restore here; the command stops at the client check before looking at the file.');
        }

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => '/nonexistent/critter.dump', '--force' => true]));
        self::assertStringContainsString('No such dump file', $tester->getDisplay());
    }
}
