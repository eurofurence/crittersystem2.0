<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SecretCipher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a fresh APP_ENCRYPTION_KEY value. Operators copy it into their secret
 * store (Infisical in production) before first use of any encrypted field.
 */
#[AsCommand(
    name: 'app:encryption:generate-key',
    description: 'Generate a random key for APP_ENCRYPTION_KEY.',
)]
final class GenerateEncryptionKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = SecretCipher::generateKey();

        $io->writeln($key);
        $io->newLine();
        $io->comment('Set this as APP_ENCRYPTION_KEY. Keep it secret; losing it makes encrypted data unrecoverable.');

        return Command::SUCCESS;
    }
}
