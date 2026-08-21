<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\GoodieCategory;
use App\Entity\GoodieDistribution;
use App\Entity\GoodieItem;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Handing out a certification-gated goodie at the distribution desk, and the override that lets the
 * desk do it anyway once somebody has said why.
 */
final class GoodieCertificationDistributionTest extends DatabaseWebTestCase
{
    private function desk(): User
    {
        $group = new Group('Desk', 'desk-'.bin2hex(random_bytes(3)));
        foreach (['goodie:view', 'goodie:distribute', 'backstage:view', 'user:locate'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('desk')->setEmail('desk@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    private function volunteer(): User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = new User();
        $user->setName('vol-'.$suffix)->setEmail('vol-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);

        return $user;
    }

    private function gatedItem(string $name, Certification $certification): GoodieItem
    {
        $category = new GoodieCategory('Swag '.bin2hex(random_bytes(3)));
        $this->em->persist($category);
        $item = (new GoodieItem($category, $name))->setRequiredHours(0.0);
        $item->addCertification($certification);
        $this->em->persist($item);

        return $item;
    }

    private function certification(string $title): Certification
    {
        $certification = (new Certification($title))->setIsActive(true);
        $this->em->persist($certification);

        return $certification;
    }

    /**
     * Submits the handover the desk actually sees, so the request carries the CSRF token the page
     * minted rather than one manufactured out of band.
     *
     * An item the volunteer is qualified for is handed over from the open tab, which posts the
     * bulk form with the row's button; a gated one only appears in the override pane, with its own
     * reason field.
     */
    private function give(User $volunteer, GoodieItem $item, ?string $overrideReason = null): void
    {
        $crawler = $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('button[name="only"][value="'.$item->getUuid().'"]');
        if (\count($row) > 0) {
            $this->client->submit($row->form());

            return;
        }

        $override = $crawler->filter('form[action*="/give"] input[name="item"][value="'.$item->getUuid().'"]');
        self::assertGreaterThan(0, \count($override), 'the desk was offered no way at all to hand this item over');

        $form = $override->closest('form')->form();
        $form['quantity'] = '1';
        if ($form->has('override_reason')) {
            $form['override_reason'] = (string) $overrideReason;
        }

        $this->client->submit($form);
    }

    /** @return GoodieDistribution[] */
    private function distributions(User $volunteer): array
    {
        return $this->em->getRepository(GoodieDistribution::class)->findBy(['user' => $volunteer]);
    }

    public function testTheDeskCannotGiveAGatedItemWithoutAReason(): void
    {
        $certification = $this->certification('First Aid');
        $item = $this->gatedItem('First Aid Pin', $certification);
        $volunteer = $this->volunteer();
        $desk = $this->desk();
        $this->em->flush();

        $this->client->loginUser($desk);
        $this->give($volunteer, $item);

        self::assertSame([], $this->distributions($volunteer), 'a missing certification must stop the handover');
    }

    public function testTheOverrideHandsItOverAndRecordsWhy(): void
    {
        $certification = $this->certification('First Aid');
        $item = $this->gatedItem('First Aid Pin', $certification);
        $volunteer = $this->volunteer();
        $desk = $this->desk();
        $this->em->flush();

        $this->client->loginUser($desk);
        $this->give($volunteer, $item, 'Trained on site by the team lead');

        $distributions = $this->distributions($volunteer);
        self::assertCount(1, $distributions);
        self::assertSame('Trained on site by the team lead', $distributions[0]->getCertificationOverrideReason());
        self::assertTrue($distributions[0]->isCertificationOverridden());
        self::assertSame($desk->getId(), $distributions[0]->getDistributedBy()?->getId(), 'the record must say who overrode it');
    }

    /** An override answers for missing training, not for hours nobody worked. */
    public function testTheOverrideDoesNotBypassTheHoursRequirement(): void
    {
        $certification = $this->certification('First Aid');
        $item = $this->gatedItem('First Aid Pin', $certification)->setRequiredHours(20.0);
        $volunteer = $this->volunteer();
        $desk = $this->desk();
        $this->em->flush();

        $this->client->loginUser($desk);
        $this->give($volunteer, $item, 'Trained on site');

        self::assertSame([], $this->distributions($volunteer), 'the hours requirement is not overridable');
    }

    public function testAnOrdinaryHandoverRecordsNoOverride(): void
    {
        $certification = $this->certification('First Aid');
        $item = $this->gatedItem('First Aid Pin', $certification);
        $volunteer = $this->volunteer();
        $desk = $this->desk();
        $record = new UserCertification($volunteer, $certification);
        $record->setStatus(UserCertification::STATUS_APPROVED);
        $this->em->persist($record);
        $this->em->flush();

        $this->client->loginUser($desk);
        $this->give($volunteer, $item);

        $distributions = $this->distributions($volunteer);
        self::assertCount(1, $distributions);
        self::assertNull($distributions[0]->getCertificationOverrideReason());
    }

    public function testTheDeskScreenNamesTheMissingCertification(): void
    {
        $certification = $this->certification('First Aid');
        $this->gatedItem('First Aid Pin', $certification);
        $volunteer = $this->volunteer();
        $desk = $this->desk();
        $this->em->flush();

        $this->client->loginUser($desk);
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('First Aid', $body);
        self::assertStringContainsString('override_reason', $body, 'the desk needs somewhere to say why');
    }
}
