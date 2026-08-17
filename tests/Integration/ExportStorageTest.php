<?php

namespace App\Tests\Integration;

use App\Audit\AuditExporter;
use App\Entity\DataExport;
use App\Entity\User;
use App\Gdpr\GenerateDataExport;
use App\Storage\ExportStorage;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Export archives must live in the shared export storage, never on the local disk of whichever
 * container happened to build them: the messenger worker writes a GDPR archive and a *different*
 * process serves the download, and in Kubernetes the two share no filesystem at all.
 *
 * Also protects the retention rule - an expired archive is a full copy of a user's personal data and
 * has to be deleted, not merely hidden behind an expiry check.
 */
final class ExportStorageTest extends DatabaseTestCase
{
    private function storage(): ExportStorage
    {
        return static::getContainer()->get(ExportStorage::class);
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testTheGdprArchiveIsAddressedByStorageKeyNotALocalPath(): void
    {
        $export = new DataExport($this->makeUser('walker'), 'uuid-key-1');
        $this->em->persist($export);
        $this->em->flush();

        static::getContainer()->get(MessageBusInterface::class)->dispatch(new GenerateDataExport($export->getId()));
        $this->em->clear();

        $key = (string) $this->em->getRepository(DataExport::class)->find($export->getId())->getStorageKey();

        self::assertSame('gdpr/uuid-key-1.zip', $key);
        self::assertStringNotContainsString('/var/', $key, 'a local path here is invisible to the process serving the download');
        self::assertTrue($this->storage()->exists($key));

        $this->storage()->delete($key);
    }

    public function testTheAuditArchiveIsAddressedByStorageKeyNotALocalPath(): void
    {
        $export = static::getContainer()->get(AuditExporter::class)
            ->export(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'), null, null);

        $key = $export->getStorageKey();

        self::assertStringStartsWith('audit/', $key);
        self::assertStringNotContainsString('/var/', $key);
        self::assertTrue($this->storage()->exists($key));

        $this->storage()->delete($key);
    }

    /** The expiry has no setter, so reflection ages the export past its download window. */
    public function testPurgeDeletesTheArchiveOfAnExpiredGdprExport(): void
    {
        $export = new DataExport($this->makeUser('forgetme'), 'uuid-purge-1');
        $this->em->persist($export);
        $this->em->flush();

        static::getContainer()->get(MessageBusInterface::class)->dispatch(new GenerateDataExport($export->getId()));

        $key = (string) $export->getStorageKey();
        self::assertTrue($this->storage()->exists($key));

        $expiry = new \ReflectionProperty(DataExport::class, 'expiresAt');
        $expiry->setValue($export, new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:gdpr:purge-exports'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertFalse(
            $this->storage()->exists($key),
            'an expired archive is a full copy of the personal data and must actually be deleted',
        );

        $this->em->clear();
        $reloaded = $this->em->getRepository(DataExport::class)->find($export->getId());
        self::assertNotNull($reloaded, 'the record survives so the request stays auditable');
        self::assertNull($reloaded->getStorageKey());
        self::assertFalse($reloaded->isDownloadable());
    }
}
