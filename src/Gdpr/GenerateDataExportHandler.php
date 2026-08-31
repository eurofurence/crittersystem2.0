<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Entity\DataExport;
use App\Storage\ExportStorage;
use App\Storage\ZipBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
    public const KEY_PREFIX = 'gdpr/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DataExportBuilder $builder,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ExportStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A failure is logged as well as recorded on the export: the user only ever sees "failed", so the
     * reason has to reach the operator some other way.
     */
    public function __invoke(GenerateDataExport $message): void
    {
        $export = $this->em->getRepository(DataExport::class)->find($message->exportId);
        if ($export === null) {
            return;
        }

        try {
            $json = json_encode($this->builder->build($export->getUser()), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

            $key = self::KEY_PREFIX.$export->getUuid().'.zip';
            $this->storage->write($key, ZipBuilder::build(['data.json' => (string) $json]));

            $export->markReady($key);
            $this->em->flush();

            $url = $this->urlGenerator->generate('app_profile_data_download', ['uuid' => $export->getUuid()], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->mailer->send(
                (new Email())->to($export->getUser()->getEmail())
                    ->subject('Your data export is ready')
                    ->text("Your data export is ready. Download it within 24 hours:\n".$url),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Data export {uuid} failed: {reason}', [
                'uuid' => $export->getUuid(),
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);
            $export->markFailed();
            $this->em->flush();
        }
    }
}
