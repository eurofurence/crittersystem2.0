<?php

namespace App\Tests\Integration;

use App\Gdpr\BanChecker;
use App\Repository\SsoGroupMappingRepository;
use App\Repository\UserRepository;
use App\Sso\SsoAvatarFetcher;
use App\Sso\SsoClaims;
use App\Sso\SsoDepartmentPositions;
use App\Sso\SsoGlobalRoles;
use App\Sso\SsoUserProvisioner;
use App\Service\UsernameGenerator;
use App\Storage\FileStorage;
use App\Tests\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SsoAvatarTest extends DatabaseTestCase
{
    private FileStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = static::getContainer()->get(FileStorage::class);
    }

    public function testSsoAvatarIsDownloadedAndServedLocally(): void
    {
        $http = $this->imageServingClient($calls);
        $user = $this->provision($http, new SsoClaims('sub-a', 'a@example.com', 'a', 'Ann Avatar', [], 'https://idp.example/pic/a.png'));

        $key = $user->getPersonalData()?->getAvatarPath();
        self::assertNotNull($key);
        self::assertStringStartsWith('avatars/'.$user->getUuid()->toRfc4122().'/', $key, 'stored under the user, not hot-linked');
        self::assertSame('https://idp.example/pic/a.png', $user->getPersonalData()?->getAvatarSource());
        self::assertTrue($this->storage->exists($key), 'the picture bytes were written to local storage');
        self::assertSame(1, $calls);
    }

    public function testUnchangedAvatarUrlIsNotRefetched(): void
    {
        $claims = new SsoClaims('sub-b', 'b@example.com', 'b', 'Bo Bild', [], 'https://idp.example/pic/b.png');
        $http = $this->imageServingClient($calls);
        $provisioner = $this->provisioner($http);

        $first = $provisioner->provision($claims);
        $keyAfterFirst = $first->getPersonalData()?->getAvatarPath();

        $provisioner->provision($claims);
        $this->em->clear();
        $reloaded = static::getContainer()->get(UserRepository::class)->findOneBy(['ssoUserId' => 'sub-b']);

        self::assertSame($keyAfterFirst, $reloaded->getPersonalData()?->getAvatarPath(), 'key is stable');
        self::assertSame(1, $calls, 'the identical picture URL is fetched only once');
    }

    public function testManualUploadIsOverriddenByTheNextSsoLogin(): void
    {
        $claims = new SsoClaims('sub-c', 'c@example.com', 'c', 'Cy Crop', [], 'https://idp.example/pic/c.png');
        $http = $this->imageServingClient($calls);
        $provisioner = $this->provisioner($http);

        $user = $provisioner->provision($claims);
        $ssoKey = $user->getPersonalData()?->getAvatarPath();

        // Simulate a later manual upload: a new key and a cleared SSO source (see SettingsController).
        $user->getPersonalData()->setAvatarPath('avatars/manual/upload.png')->setAvatarSource(null);
        $this->em->flush();

        $provisioner->provision($claims);
        $this->em->clear();
        $reloaded = static::getContainer()->get(UserRepository::class)->findOneBy(['ssoUserId' => 'sub-c']);

        self::assertSame('https://idp.example/pic/c.png', $reloaded->getPersonalData()?->getAvatarSource());
        self::assertNotSame('avatars/manual/upload.png', $reloaded->getPersonalData()?->getAvatarPath(), 'SSO re-asserts its own picture');
        self::assertNotSame($ssoKey, $reloaded->getPersonalData()?->getAvatarPath());
        self::assertSame(2, $calls);
    }

    public function testAbsentSsoAvatarLeavesTheExistingOneUntouched(): void
    {
        $http = $this->imageServingClient($calls);
        $provisioner = $this->provisioner($http);

        $user = $provisioner->provision(new SsoClaims('sub-d', 'd@example.com', 'd', 'Di Draw', [], 'https://idp.example/pic/d.png'));
        $key = $user->getPersonalData()?->getAvatarPath();

        // A later login where the provider advertises no picture must not wipe the stored one.
        $provisioner->provision(new SsoClaims('sub-d', 'd@example.com', 'd', 'Di Draw', [], null));
        $this->em->clear();
        $reloaded = static::getContainer()->get(UserRepository::class)->findOneBy(['ssoUserId' => 'sub-d']);

        self::assertSame($key, $reloaded->getPersonalData()?->getAvatarPath());
        self::assertSame(1, $calls);
    }

    public function testANonImageResponseIsNotStored(): void
    {
        $http = new MockHttpClient(static fn () => new MockResponse('<html>not an image</html>', [
            'response_headers' => ['content-type' => 'text/html'],
        ]));
        $user = $this->provision($http, new SsoClaims('sub-e', 'e@example.com', 'e', 'Ed Empty', [], 'https://idp.example/pic/e.png'));

        self::assertNull($user->getPersonalData()?->getAvatarPath(), 'a non-image body is rejected, no avatar set');
        self::assertNull($user->getPersonalData()?->getAvatarSource());
    }

    /** A MockHttpClient that answers every request with a valid PNG and counts the calls. */
    private function imageServingClient(?int &$calls): MockHttpClient
    {
        $calls = 0;
        $png = $this->pngBytes();

        return new MockHttpClient(function () use (&$calls, $png): MockResponse {
            ++$calls;

            return new MockResponse($png, ['response_headers' => ['content-type' => 'image/png']]);
        });
    }

    private function provision(MockHttpClient $http, SsoClaims $claims): \App\Entity\User
    {
        return $this->provisioner($http)->provision($claims);
    }

    private function provisioner(MockHttpClient $http): SsoUserProvisioner
    {
        $container = static::getContainer();
        $fetcher = new SsoAvatarFetcher($http, $this->storage, $container->get(LoggerInterface::class));

        return new SsoUserProvisioner(
            $container->get(EntityManagerInterface::class),
            $container->get(UserRepository::class),
            $container->get(SsoGroupMappingRepository::class),
            $container->get(UsernameGenerator::class),
            $container->get(BanChecker::class),
            $container->get(SsoDepartmentPositions::class),
            $container->get(SsoGlobalRoles::class),
            $fetcher,
            $this->storage,
        );
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
