<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The certification-card + toggle-switch UI on the volunteer-type manage form,
 * the reusable card on the volunteer-facing certifications list, and the
 * read-only certification detail page linked from both.
 */
final class VolunteerTypeCertificationUiTest extends DatabaseWebTestCase
{
    private function login(?string $role = null): User
    {
        $group = new Group('G', 'g-'.bin2hex(random_bytes(4)), $role);
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('tester')->setEmail('tester'.bin2hex(random_bytes(3)).'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function certification(string $title, bool $staffOnly = false): Certification
    {
        $cert = (new Certification($title))
            ->setDescription($title.' description')
            ->setStaffOnly($staffOnly);
        $this->em->persist($cert);
        $this->em->flush();

        return $cert;
    }

    /**
     * Each certification is offered as a card carrying the shared component and a link to its own
     * page, and the configuration flags render as toggle switches rather than plain checkboxes.
     */
    public function testNewFormShowsCertificationCardsAndFlagSwitches(): void
    {
        $cert = $this->certification('First Aid');
        $this->login('ROLE_ADMIN');

        $crawler = $this->client->request('GET', '/manage/volunteer-types/new');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('.card-title:contains("First Aid")')->count());
        self::assertGreaterThan(0, $crawler->filter('a:contains("More information")')->count());
        self::assertStringContainsString('/certifications/'.$cert->getUuid(), $this->client->getResponse()->getContent());

        self::assertGreaterThan(0, $crawler->filter('.form-check.form-switch')->count());
    }

    /**
     * A certification chosen on the create form is stored with the type. The type is marked as shown
     * on the dashboard because the flag rules require that of a non-staff type.
     */
    public function testCreatingAVolunteerTypeSelectingACertificationPersistsIt(): void
    {
        $cert = $this->certification('First Aid');
        $this->login('ROLE_ADMIN');

        $crawler = $this->client->request('GET', '/manage/volunteer-types/new');
        $token = $crawler->filter('input[name="volunteer_type[_token]"]')->attr('value');

        $this->client->request('POST', '/manage/volunteer-types/new', ['volunteer_type' => [
            'name' => 'Marshal',
            'certifications' => [(string) $cert->getId()],
            'showOnDashboard' => '1',
            '_token' => $token,
        ]]);
        self::assertResponseRedirects('/manage/volunteer-types');

        $this->em->clear();
        $type = $this->em->getRepository(VolunteerType::class)->findOneBy(['name' => 'Marshal']);
        self::assertNotNull($type);
        self::assertCount(1, $type->getCertifications());
        self::assertSame('First Aid', $type->getCertifications()->first()->getTitle());
    }

    public function testCertificationDetailPageRendersForAnyUser(): void
    {
        $cert = $this->certification('First Aid');
        $this->login();

        $this->client->request('GET', '/certifications/'.$cert->getUuid());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'First Aid');
        self::assertSelectorTextContains('body', 'First Aid description');
    }

    public function testStaffOnlyCertificationDetailIsHiddenFromNonStaff(): void
    {
        $cert = $this->certification('Backstage Pass', staffOnly: true);
        $this->login();

        $this->client->request('GET', '/certifications/'.$cert->getUuid());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCertificationsIndexRendersCardsWithApplyAndInfoLink(): void
    {
        $cert = $this->certification('First Aid');
        $this->login();

        $crawler = $this->client->request('GET', '/certifications');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.card-title:contains("First Aid")')->count());
        self::assertGreaterThan(0, $crawler->filter('a:contains("More information")')->count());
        self::assertGreaterThan(0, $crawler->filter('button:contains("Apply")')->count());
        self::assertStringContainsString('/certifications/'.$cert->getUuid(), $this->client->getResponse()->getContent());
    }
}
