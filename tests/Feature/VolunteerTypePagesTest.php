<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VolunteerTypePagesTest extends DatabaseWebTestCase
{
    private function login(?string $role = null): User
    {
        $group = new Group('G', 'g-grp', $role);
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('viewer')->setEmail('viewer@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function publicType(): VolunteerType
    {
        $type = (new VolunteerType('Greeter'))->setStaffOnly(false)->setShowOnDashboard(true);
        $this->em->persist($type);

        return $type;
    }

    private function staffType(): VolunteerType
    {
        $type = (new VolunteerType('Backstage Crew'))->setStaffOnly(true)->setHideOnShiftView(true)->setShowOnDashboard(false);
        $this->em->persist($type);

        return $type;
    }

    public function testNonStaffSeesOnlyPublicTypes(): void
    {
        $public = $this->publicType();
        $staff = $this->staffType();
        $this->em->flush();

        $this->login(); // non-staff
        $crawler = $this->client->request('GET', '/volunteer-types');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Greeter', $crawler->text());
        self::assertStringNotContainsString('Backstage Crew', $crawler->text());

        $this->client->request('GET', '/volunteer-types/'.$staff->getUuid());
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaffSeesStaffType(): void
    {
        $staff = $this->staffType();
        $this->em->flush();

        $this->login('ROLE_STAFF');
        $this->client->request('GET', '/volunteer-types/'.$staff->getUuid());
        self::assertResponseIsSuccessful();
    }

    public function testApplyToRequiresIntroductionCreatesPending(): void
    {
        $type = (new VolunteerType('Trainee'))->setStaffOnly(false)->setShowOnDashboard(true)->setRestricted(true);
        $this->em->persist($type);
        $this->em->flush();

        $user = $this->login();
        $crawler = $this->client->request('GET', '/volunteer-types/'.$type->getUuid());
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/volunteer-types/'.$type->getUuid().'/join', ['_token' => $token]);
        self::assertResponseRedirects();

        $membership = $this->em->getRepository(UserVolunteerType::class)->findOneBy(['user' => $user, 'volunteerType' => $type]);
        self::assertNotNull($membership);
        self::assertFalse($membership->isConfirmed());
    }
}
