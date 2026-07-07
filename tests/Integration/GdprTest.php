<?php

namespace App\Tests\Integration;

use App\Entity\DataExport;
use App\Entity\ErasureRequest;
use App\Entity\PersonalData;
use App\Entity\Settings;
use App\Entity\User;
use App\Gdpr\BanChecker;
use App\Gdpr\DataExportBuilder;
use App\Gdpr\ErasureService;
use App\Gdpr\GenerateDataExport;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GdprTest extends DatabaseTestCase
{
    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->setPersonalData((new PersonalData($user))->setFirstName('Test')->setLastName('User'));
        $user->setSettings(new Settings($user));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testDataExportBuilderProducesExpectedShape(): void
    {
        /** @var DataExportBuilder $builder */
        $builder = static::getContainer()->get(DataExportBuilder::class);
        $data = $builder->build($this->makeUser('exporter'));

        self::assertSame('exporter', $data['profile']['username']);
        self::assertSame('Test User', $data['profile']['full_name']);
        foreach (['profile', 'consent', 'volunteer_types', 'shifts', 'hours', 'goodies'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
    }

    public function testQueuedExportBuildsDownloadableArchive(): void
    {
        $user = $this->makeUser('archiver');
        $export = new DataExport($user, 'uuid-test-1');
        $this->em->persist($export);
        $this->em->flush();

        // Routed to sync under test, so the handler runs inline.
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new GenerateDataExport($export->getId()));

        $this->em->clear();
        $reloaded = $this->em->getRepository(DataExport::class)->find($export->getId());
        self::assertTrue($reloaded->isReady());
        self::assertTrue($reloaded->isDownloadable());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($reloaded->getFilePath()));
        self::assertNotFalse($zip->getFromName('data.json'));
        $zip->close();
        @unlink($reloaded->getFilePath());
    }

    public function testBanCheckerHashesEmailAndSsoSeparately(): void
    {
        /** @var BanChecker $bans */
        $bans = static::getContainer()->get(BanChecker::class);

        self::assertNotSame($bans->hashEmail('a@b.com'), $bans->hashSso('a@b.com'));
        self::assertFalse($bans->isEmailBanned('a@b.com'));
    }

    public function testErasureDeletesUserAndBansThem(): void
    {
        $user = $this->makeUser('goner');
        $email = $user->getEmail();
        $userId = $user->getId();
        $request = new ErasureRequest($user, 'erase-token-1');
        $this->em->persist($request);
        $this->em->flush();

        /** @var ErasureService $erasure */
        $erasure = static::getContainer()->get(ErasureService::class);
        $erasure->execute($request);

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($userId), 'user removed');
        self::assertTrue(static::getContainer()->get(BanChecker::class)->isEmailBanned($email), 'identity banned');
    }
}
