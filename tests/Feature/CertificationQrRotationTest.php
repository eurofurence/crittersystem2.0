<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\CertificationToken;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The scanning QR rotates itself without reloading the document.
 *
 * The page is reached by Turbo, which swaps the body and never loads a document, so a
 * `<meta http-equiv="refresh">` here is scheduled against the URL that was current when it was
 * parsed and fires long after the user has moved on, pulling them back. The card declares the
 * instant it is replaced instead, and the live region re-fetches it once at that moment.
 *
 * The declared instant must always lie ahead, which is why a token already inside the rotation
 * margin is replaced rather than shown: the region would otherwise be handed a moment in the past
 * and fetch again immediately, on a one second floor, for as long as the page stayed open.
 */
final class CertificationQrRotationTest extends DatabaseWebTestCase
{
    private function manager(): User
    {
        $group = new Group('Cert managers', 'certmgr-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'certification:manage']) ?? new Privilege('certification:manage');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('mgr-'.bin2hex(random_bytes(3)))->setEmail('mgr-'.bin2hex(random_bytes(3)).'@example.com');
        $user->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function certification(): Certification
    {
        $certification = new Certification('Food handling');
        $this->em->persist($certification);
        $this->em->flush();

        return $certification;
    }

    /**
     * The shell carries live regions of its own, so the card's region is identified by the endpoint
     * it points at rather than by being the only one on the page.
     */
    private function qrRegion(Crawler $crawler, Certification $certification): Crawler
    {
        $cardUrl = sprintf('/manage/certifications/%s/qr/card', $certification->getUuid());

        return $crawler->filter('[data-controller="live-stream"]')->reduce(
            static fn (Crawler $node): bool => str_contains((string) $node->attr('data-live-stream-url-value'), $cardUrl),
        );
    }

    public function testThePageDrivesTheRotationFromALiveRegionRatherThanADocumentRefresh(): void
    {
        $this->manager();
        $certification = $this->certification();

        $crawler = $this->client->request('GET', sprintf('/manage/certifications/%s/qr', $certification->getUuid()));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->qrRegion($crawler, $certification)->count());

        self::assertStringNotContainsString(
            'http-equiv="refresh"',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** The chain only continues if the marker is on the fragment, which the refresh replaces. */
    public function testTheCardFragmentCarriesTheNextTransitionAndIsNotADocument(): void
    {
        $this->manager();
        $certification = $this->certification();

        $this->client->request('GET', sprintf('/manage/certifications/%s/qr/card', $certification->getUuid()));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<html', $html, 'the live region refuses a whole document');
        self::assertMatchesRegularExpression('/data-next-transition="[^"]+"/', $html);
    }

    public function testTheDeclaredRotationMomentAlwaysLiesAhead(): void
    {
        $this->manager();
        $certification = $this->certification();

        $crawler = $this->client->request('GET', sprintf('/manage/certifications/%s/qr', $certification->getUuid()));

        self::assertResponseIsSuccessful();
        $declared = (string) $this->qrRegion($crawler, $certification)->filter('[data-next-transition]')->attr('data-next-transition');

        self::assertGreaterThan(
            time(),
            (new \DateTimeImmutable($declared))->getTimestamp(),
            'a moment in the past makes the region refetch on its one second floor',
        );
    }

    public function testATokenInsideTheRotationMarginIsReplacedRatherThanShown(): void
    {
        $this->manager();
        $certification = $this->certification();

        $expiring = new CertificationToken($certification, 10);
        $this->em->persist($expiring);
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/manage/certifications/%s/qr', $certification->getUuid()));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            $expiring->getToken(),
            (string) $this->client->getResponse()->getContent(),
            'a token about to expire must not be put on screen',
        );

        $declared = (string) $this->qrRegion($crawler, $certification)->filter('[data-next-transition]')->attr('data-next-transition');
        self::assertGreaterThan(time(), (new \DateTimeImmutable($declared))->getTimestamp());
    }
}
