<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Entity\DataExport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the user's data-portability archive off the request path, stores it,
 * and emails the user a time-limited download link.
 */
#[AsMessageHandler]
final class GenerateDataExportHandler
{
    private const FROM = 'noreply@critter.example';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DataExportBuilder $builder,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(GenerateDataExport $message): void
    {
        $export = $this->em->getRepository(DataExport::class)->find($message->exportId);
        if ($export === null) {
            return;
        }

        try {
            $dir = $this->projectDir.'/var/data-exports';
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create export directory.');
            }
            $path = $dir.'/'.$export->getUuid().'.zip';

            $json = json_encode($this->builder->build($export->getUser()), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create archive.');
            }
            $zip->addFromString('data.json', (string) $json);
            $zip->close();

            $export->markReady($path);
            $this->em->flush();

            $url = $this->urlGenerator->generate('app_profile_data_download', ['uuid' => $export->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->mailer->send(
                (new Email())->from(self::FROM)->to($export->getUser()->getEmail())
                    ->subject('Your data export is ready')
                    ->text("Your data export is ready. Download it within 24 hours:\n".$url),
            );
        } catch (\Throwable) {
            $export->markFailed();
            $this->em->flush();
        }
    }
}
