<?php

namespace App\Tests\Feature;

use App\Entity\GoodieCategory;
use App\Entity\GoodieDistribution;
use App\Entity\GoodieItem;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Repository\GoodieDistributionRepository;
use App\Tests\DatabaseWebTestCase;

/**
 * The info desk marks goodies as handed over at the counter and gets it wrong: the wrong volunteer,
 * the wrong number, a click nobody meant. These protect the two things that makes survivable.
 *
 * A revoked handover keeps its record but stops counting anywhere it would otherwise deny the
 * volunteer something: the per-person limit, the eligibility tiers and their own list of what they
 * received. Only the desk history still shows it, marked, which is what lets one operator see that
 * another already corrected the mistake.
 */
final class GoodieRevocationTest extends DatabaseWebTestCase
{
    private function operator(string $name, string ...$privileges): User
    {
        $group = new Group(ucfirst($name), $name.'-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        foreach ($privileges as $privilegeName) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $privilegeName]) ?? new Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function desk(): User
    {
        return $this->operator('desk', 'user:locate', 'goodie:view', 'goodie:distribute');
    }

    private function volunteer(): User
    {
        $suffix = bin2hex(random_bytes(4));
        $user = new User();
        $user->setName('vol-'.$suffix)->setEmail('vol-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function item(string $name, ?int $maxPerPerson = null): GoodieItem
    {
        $category = new GoodieCategory('Swag '.bin2hex(random_bytes(3)));
        $this->em->persist($category);
        $item = (new GoodieItem($category, $name))->setRequiredHours(0.0)->setMaxPerPerson($maxPerPerson);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function handed(User $volunteer, GoodieItem $item, int $quantity = 1): GoodieDistribution
    {
        $distribution = new GoodieDistribution($volunteer, $item, $quantity);
        $this->em->persist($distribution);
        $this->em->flush();

        return $distribution;
    }

    /** @return GoodieDistribution[] */
    private function distributions(User $volunteer): array
    {
        $this->em->clear();

        return $this->em->getRepository(GoodieDistribution::class)->findBy(['user' => $volunteer], ['id' => 'ASC']);
    }

    private function open(User $volunteer): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function token(\Symfony\Component\DomCrawler\Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action$="'.$action.'"] input[name="_token"]')->first()->attr('value');
    }

    public function testTheDeskHandsSeveralItemsOverInOneSubmission(): void
    {
        $desk = $this->desk();
        $volunteer = $this->volunteer();
        $cup = $this->item('Festival Cup');
        $shirt = $this->item('Crew Shirt');

        $this->client->loginUser($desk);
        $crawler = $this->open($volunteer);

        $form = $crawler->filter('form[action$="/give-bulk"]')->form();
        foreach ($form['items'] as $checkbox) {
            $checkbox->tick();
        }
        $form->setValues([
            'quantities' => [(string) $cup->getUuid() => '2', (string) $shirt->getUuid() => '1'],
            'notes' => 'Collected together at the desk',
        ]);
        $this->client->submit($form);

        $distributions = $this->distributions($volunteer);
        self::assertCount(2, $distributions, 'both ticked items must be handed over by the one submit');

        $handed = [];
        foreach ($distributions as $distribution) {
            $handed[$distribution->getItemName()] = $distribution->getQuantity();
            self::assertSame('Collected together at the desk', $distribution->getNotes());
            self::assertSame($desk->getId(), $distribution->getDistributedBy()?->getId());
        }
        self::assertSame(['Crew Shirt' => 1, 'Festival Cup' => 2], $handed);
    }

    /** The row button shares the bulk form, so it must still hand over its own item alone. */
    public function testARowButtonHandsOverOnlyItsOwnItem(): void
    {
        $this->client->loginUser($this->desk());
        $volunteer = $this->volunteer();
        $cup = $this->item('Festival Cup');
        $this->item('Crew Shirt');

        $crawler = $this->open($volunteer);
        $this->client->submit($crawler->filter('button[name="only"][value="'.$cup->getUuid().'"]')->form());

        $distributions = $this->distributions($volunteer);
        self::assertCount(1, $distributions);
        self::assertSame('Festival Cup', $distributions[0]->getItemName());
    }

    public function testNothingIsHandedOverWhenNothingIsTicked(): void
    {
        $this->client->loginUser($this->desk());
        $volunteer = $this->volunteer();
        $this->item('Festival Cup');

        $crawler = $this->open($volunteer);
        $this->client->submit($crawler->filter('form[action$="/give-bulk"]')->form());

        self::assertSame([], $this->distributions($volunteer));
    }

    /**
     * The point of the whole feature: an item wrongly marked as delivered must become claimable
     * again, which it only does if the revoked row stops counting towards the per-person limit.
     */
    public function testRevokingReleasesThePerPersonLimitAndOffersTheItemAgain(): void
    {
        $desk = $this->desk();
        $volunteer = $this->volunteer();
        $item = $this->item('Festival Cup', 1);
        $this->handed($volunteer, $item);

        $this->client->loginUser($desk);
        $crawler = $this->open($volunteer);
        self::assertStringNotContainsString(
            'name="only" value="'.$item->getUuid().'"',
            (string) $this->client->getResponse()->getContent(),
            'an item at its limit is not on offer before the revoke',
        );

        $this->client->submit($crawler->filter('form[action$="/revoke"] button[name="distribution"]:not([form])')->first()->form());

        $distributions = $this->distributions($volunteer);
        self::assertCount(1, $distributions, 'the record is kept, not deleted');
        self::assertTrue($distributions[0]->isRevoked());
        self::assertSame($desk->getId(), $distributions[0]->getRevokedBy()?->getId());

        $repository = static::getContainer()->get(GoodieDistributionRepository::class);
        self::assertSame(0, $repository->quantityForUserAndItem($volunteer, $item));
        self::assertSame(0, $repository->totalQuantityForUser($volunteer));

        $this->open($volunteer);
        self::assertStringContainsString(
            'name="only" value="'.$item->getUuid().'"',
            (string) $this->client->getResponse()->getContent(),
            'the item must be claimable again once the mistake is undone',
        );
    }

    public function testSeveralHandoversAreRevokedTogetherWithOneReason(): void
    {
        $this->client->loginUser($this->desk());
        $volunteer = $this->volunteer();
        $cup = $this->item('Festival Cup');
        $shirt = $this->item('Crew Shirt');
        $first = $this->handed($volunteer, $cup);
        $second = $this->handed($volunteer, $shirt);

        $crawler = $this->open($volunteer);
        $form = $crawler->filter('form[action$="/revoke"]')->form();
        $form->setValues([
            'distributions' => [(string) $first->getUuid(), (string) $second->getUuid()],
            'reason' => 'Marked by mistake at the desk',
        ]);
        $this->client->submit($form);

        foreach ($this->distributions($volunteer) as $distribution) {
            self::assertTrue($distribution->isRevoked());
            self::assertSame('Marked by mistake at the desk', $distribution->getRevokeReason());
        }
    }

    /**
     * The desk has to keep seeing the correction; the volunteer's own record must not show a goodie
     * they never kept.
     */
    public function testARevokedHandoverStaysInTheDeskHistoryOnly(): void
    {
        $desk = $this->desk();
        $volunteer = $this->volunteer();
        $item = $this->item('Festival Cup');
        $distribution = $this->handed($volunteer, $item);

        $this->client->loginUser($desk);
        $crawler = $this->open($volunteer);
        $this->client->submit($crawler->filter('form[action$="/revoke"] button[name="distribution"]:not([form])')->first()->form());

        $repository = static::getContainer()->get(GoodieDistributionRepository::class);
        self::assertSame([], $repository->findByUser($volunteer));
        self::assertCount(1, $repository->findByUser($volunteer, 20, true));
        self::assertSame([], $repository->findRecent());
        self::assertSame(0, $repository->countSince(new \DateTimeImmutable('-1 day')));

        $this->open($volunteer);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Revoked', $html);
        self::assertStringNotContainsString('value="'.$distribution->getUuid().'"', $html, 'a revoked row offers no further action');
    }

    public function testCorrectingTheQuantityKeepsWhatTheRowFirstSaid(): void
    {
        $desk = $this->desk();
        $volunteer = $this->volunteer();
        $item = $this->item('Festival Cup');
        $distribution = $this->handed($volunteer, $item, 3);

        $this->client->loginUser($desk);
        $crawler = $this->open($volunteer);

        $form = $crawler->filter('button[form="goodie-correct-form"][value="'.$distribution->getUuid().'"]')->form();
        $form->setValues(['quantities' => [(string) $distribution->getUuid() => '2']]);
        $this->client->submit($form);

        $corrected = $this->distributions($volunteer)[0];
        self::assertSame(2, $corrected->getQuantity());
        self::assertSame(3, $corrected->getOriginalQuantity());
        self::assertTrue($corrected->isQuantityCorrected());
        self::assertSame($desk->getId(), $corrected->getCorrectedBy()?->getId());
    }

    /** Nothing handed over at all is a revoke, and must not be reachable by typing a zero. */
    public function testCorrectingToNothingIsRefused(): void
    {
        $this->client->loginUser($this->desk());
        $volunteer = $this->volunteer();
        $distribution = $this->handed($volunteer, $this->item('Festival Cup'), 2);

        $crawler = $this->open($volunteer);
        $this->client->request('POST', '/backstage/distribute/'.$volunteer->getUuid().'/correct', [
            '_token' => $this->token($crawler, '/correct'),
            'distribution' => (string) $distribution->getUuid(),
            'quantities' => [(string) $distribution->getUuid() => '0'],
        ]);

        $unchanged = $this->distributions($volunteer)[0];
        self::assertSame(2, $unchanged->getQuantity());
        self::assertFalse($unchanged->isRevoked());
        self::assertFalse($unchanged->isQuantityCorrected());
    }

    public function testACorrectionCannotPassThePerPersonLimit(): void
    {
        $this->client->loginUser($this->desk());
        $volunteer = $this->volunteer();
        $item = $this->item('Festival Cup', 2);
        $distribution = $this->handed($volunteer, $item, 2);

        $crawler = $this->open($volunteer);
        $this->client->request('POST', '/backstage/distribute/'.$volunteer->getUuid().'/correct', [
            '_token' => $this->token($crawler, '/correct'),
            'distribution' => (string) $distribution->getUuid(),
            'quantities' => [(string) $distribution->getUuid() => '5'],
        ]);

        self::assertSame(2, $this->distributions($volunteer)[0]->getQuantity());
    }

    /**
     * Addressing another volunteer's handover through this page must not tell the caller whether it
     * exists, so it answers 404 rather than 403.
     */
    public function testAHandoverBelongingToSomebodyElseIsNotFound(): void
    {
        $this->client->loginUser($this->desk());
        $volunteer = $this->volunteer();
        $other = $this->volunteer();
        $foreign = $this->handed($other, $this->item('Festival Cup'));
        $this->handed($volunteer, $this->item('Crew Shirt'));

        $crawler = $this->open($volunteer);
        $this->client->request('POST', '/backstage/distribute/'.$volunteer->getUuid().'/revoke', [
            '_token' => $this->token($crawler, '/revoke'),
            'distribution' => (string) $foreign->getUuid(),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->distributions($other)[0]->isRevoked());
    }

    public function testAnOperatorWhoMayNotHandOutCannotRevoke(): void
    {
        $reader = $this->operator('reader', 'user:locate', 'goodie:view');
        $volunteer = $this->volunteer();
        $distribution = $this->handed($volunteer, $this->item('Festival Cup'));

        $this->client->loginUser($reader);
        $this->open($volunteer);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('/revoke', $html, 'a read-only operator is offered no revoke');
        self::assertStringNotContainsString('/correct', $html);

        $this->client->request('POST', '/backstage/distribute/'.$volunteer->getUuid().'/revoke', [
            'distribution' => (string) $distribution->getUuid(),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->distributions($volunteer)[0]->isRevoked());
    }

    /** Revoking twice is the second operator finding the mistake already fixed, not a second event. */
    public function testRevokingAnAlreadyRevokedHandoverChangesNothing(): void
    {
        $desk = $this->desk();
        $volunteer = $this->volunteer();
        $distribution = $this->handed($volunteer, $this->item('Festival Cup'));

        $this->client->loginUser($desk);
        $crawler = $this->open($volunteer);
        $token = $this->token($crawler, '/revoke');
        $url = '/backstage/distribute/'.$volunteer->getUuid().'/revoke';

        $this->client->request('POST', $url, ['_token' => $token, 'distribution' => (string) $distribution->getUuid(), 'reason' => 'first']);
        $first = $this->distributions($volunteer)[0]->getRevokedAt();

        $this->client->request('POST', $url, ['_token' => $token, 'distribution' => (string) $distribution->getUuid(), 'reason' => 'second']);
        $after = $this->distributions($volunteer)[0];

        self::assertEquals($first, $after->getRevokedAt());
        self::assertSame('first', $after->getRevokeReason());
    }
}
