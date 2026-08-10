<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\GoodieCategory;
use App\Entity\GoodieItem;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Attaching required certifications to a goodie item on the backstage screen.
 */
final class GoodieItemCertificationsTest extends DatabaseWebTestCase
{
    private function loginManager(): void
    {
        $group = new Group('Goodies', 'goodies-'.bin2hex(random_bytes(2)));
        foreach (['goodie:manage', 'backstage:view'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('goodies')->setEmail('goodies@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function certification(string $title, bool $active = true): Certification
    {
        $certification = (new Certification($title))->setIsActive($active);
        $this->em->persist($certification);

        return $certification;
    }

    private function item(string $name): GoodieItem
    {
        $category = new GoodieCategory('Swag');
        $this->em->persist($category);
        $item = (new GoodieItem($category, $name))->setRequiredHours(0.0);
        $this->em->persist($item);

        return $item;
    }

    public function testAnItemCanBeGivenARequiredCertification(): void
    {
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin');
        $this->em->flush();

        $this->loginManager();
        $crawler = $this->client->request('GET', '/backstage/goodies/items/'.$item->getUuid().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['goodie_item[certifications]'] = [(string) $certification->getId()];
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(GoodieItem::class)->find($item->getId());
        self::assertSame(['First Aid'], array_map(
            static fn (Certification $c): string => $c->getTitle(),
            $stored->getCertifications()->toArray(),
        ));
    }

    /**
     * A deactivated certification stops gating but must stay attached: dropping it from the choice
     * list would silently detach it on the next save, so reactivating would not restore the rule.
     */
    public function testADeactivatedCertificationSurvivesAnUnrelatedEdit(): void
    {
        $certification = $this->certification('Retired Training', active: false);
        $item = $this->item('Old Pin');
        $item->addCertification($certification);
        $this->em->flush();

        $this->loginManager();
        $crawler = $this->client->request('GET', '/backstage/goodies/items/'.$item->getUuid().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['goodie_item[name]'] = 'Old Pin renamed';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(GoodieItem::class)->find($item->getId());
        self::assertSame('Old Pin renamed', $stored->getName());
        self::assertCount(1, $stored->getCertifications(), 'a deactivated certification must not be dropped by an unrelated save');
    }

    public function testTheItemListNamesTheRequirements(): void
    {
        $certification = $this->certification('First Aid');
        $item = $this->item('First Aid Pin');
        $item->addCertification($certification);
        $this->em->flush();

        $this->loginManager();
        $this->client->request('GET', '/backstage/goodies/items');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('First Aid', (string) $this->client->getResponse()->getContent());
    }
}
