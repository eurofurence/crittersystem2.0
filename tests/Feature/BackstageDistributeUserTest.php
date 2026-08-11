<?php

namespace App\Tests\Feature;

use App\Entity\Certification;
use App\Entity\GoodieCategory;
use App\Entity\GoodieDistribution;
use App\Entity\GoodieItem;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * The info-desk volunteer page shows three panels, and each one answers to a privilege of its own:
 * an operator who may locate a volunteer is not thereby entitled to see what they have been given or
 * where they are rostered. The certification override lives behind a further gate again, because
 * handing over a gated item is an audit-logged decision rather than part of the routine hand-out.
 */
final class BackstageDistributeUserTest extends DatabaseWebTestCase
{
    private function operator(string $name, string ...$privileges): User
    {
        return $this->operatorWithRole('ROLE_STAFF', $name, ...$privileges);
    }

    private function operatorWithRole(?string $role, string $name, string ...$privileges): User
    {
        $group = new Group(ucfirst($name), $name.'-'.bin2hex(random_bytes(3)), $role);
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

    private function item(string $name, ?Certification $certification = null): GoodieItem
    {
        $category = new GoodieCategory('Swag '.bin2hex(random_bytes(3)));
        $this->em->persist($category);
        $item = (new GoodieItem($category, $name))->setRequiredHours(0.0);
        if ($certification !== null) {
            $item->addCertification($certification);
        }
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function certification(string $title): Certification
    {
        $certification = (new Certification($title))->setIsActive(true);
        $this->em->persist($certification);
        $this->em->flush();

        return $certification;
    }

    private function pane(string $html, string $id): string
    {
        $start = strpos($html, 'id="'.$id.'"');
        self::assertNotFalse($start, sprintf('the "%s" pane is missing from the page', $id));

        $next = strpos($html, 'id="goodie-pane-', $start + 1);

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    /**
     * A gated item must not appear among the items the desk can simply hand over: the only place it
     * is offered is the pane that also demands a reason.
     */
    public function testAGatedItemIsOfferedOnlyInTheBlockedPane(): void
    {
        $desk = $this->operator('desk', 'user:locate', 'goodie:view', 'goodie:distribute');
        $volunteer = $this->volunteer();
        $this->item('Festival Cup');
        $this->item('First Aid Pin', $this->certification('First Aid'));

        $this->client->loginUser($desk);
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Festival Cup', $this->pane($html, 'goodie-pane-open'));
        self::assertStringNotContainsString('First Aid Pin', $this->pane($html, 'goodie-pane-open'));
        self::assertStringContainsString('First Aid Pin', $this->pane($html, 'goodie-pane-blocked'));
        self::assertStringContainsString('override_reason', $this->pane($html, 'goodie-pane-blocked'));
    }

    /**
     * Locating a volunteer says nothing about being entitled to their goodie record. Neither the
     * ladder, the hand-out panes nor the distribution history may appear without goodie:view.
     */
    public function testWithoutTheGoodieViewPrivilegeNoGoodieDataIsRendered(): void
    {
        $volunteer = $this->volunteer();
        $item = $this->item('Festival Cup');
        $this->em->persist(new GoodieDistribution($volunteer, $item, 1));
        $this->em->flush();

        $this->client->loginUser($this->operator('plain', 'user:locate', 'shift:view'));
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Festival Cup', $html, 'the distribution history must not leak to an operator who cannot see goodies');
        self::assertStringNotContainsString('goodie-pane-open', $html);
        self::assertStringNotContainsString('reward-timeline', $html);
    }

    /**
     * Seeing what somebody is entitled to is not being allowed to hand it over, and the override is
     * a further step again: neither form may be rendered for a read-only operator.
     */
    public function testAReadOnlyOperatorGetsNoHandoverFormAndNoBlockedPane(): void
    {
        $desk = $this->operator('reader', 'user:locate', 'goodie:view');
        $volunteer = $this->volunteer();
        $this->item('Festival Cup');
        $this->item('First Aid Pin', $this->certification('First Aid'));

        $this->client->loginUser($desk);
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Festival Cup', $this->pane($html, 'goodie-pane-open'));
        self::assertStringNotContainsString('/give', $html);
        self::assertStringNotContainsString('goodie-pane-blocked', $html);
        self::assertStringNotContainsString('override_reason', $html);
    }

    public function testTheIdentityCardShowsWhatTheProfilePageOpensWith(): void
    {
        $volunteer = $this->volunteer();

        $this->client->loginUser($this->operator('desk', 'user:locate'));
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();

        $text = $this->client->getCrawler()->filter('body')->text();
        self::assertStringContainsString($volunteer->getName(), $text);
        self::assertStringContainsString('Total shifts', $text);
        self::assertStringContainsString('Planned arrival', $text);
    }

    /**
     * The card repeats what /users/{uuid} shows, so an operator that page would turn away must not be
     * handed the same facts here - nor a link to a profile that will refuse them.
     */
    public function testTheIdentityCardAndTheProfileLinkFollowProfileVisibility(): void
    {
        $volunteer = $this->volunteer();
        $outsider = $this->operatorWithRole(null, 'outsider', 'user:locate');

        $this->client->loginUser($outsider);
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Total shifts', $html);
        self::assertStringNotContainsString('/users/'.$volunteer->getUuid(), $html, 'the page must not offer a link the profile will refuse');

        // The guard on the whole point of the gate: that profile really is out of bounds for them.
        $this->client->request('GET', '/users/'.$volunteer->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    /** Check-in is stated once, beside the button that changes it. */
    public function testTheArrivalStateIsNotRepeatedAcrossTheTwoIdentityCards(): void
    {
        $volunteer = $this->volunteer();

        $this->client->loginUser($this->operator('arrivals', 'user:locate', 'user:arrive'));
        $crawler = $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());

        self::assertCount(1, $crawler->filter('form[action*="/checkin"]'));
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), 'Checked in'));
    }

    public function testTheShiftPanelNeedsTheShiftViewPrivilege(): void
    {
        $volunteer = $this->volunteer();

        $this->client->loginUser($this->operator('rostered', 'user:locate', 'shift:view'));
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertSelectorExists('table.table-thead-sticky');

        $this->client->loginUser($this->operator('unrostered', 'user:locate'));
        $this->client->request('GET', '/backstage/distribute/'.$volunteer->getUuid());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('table.table-thead-sticky');
    }
}
