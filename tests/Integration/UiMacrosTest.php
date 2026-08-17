<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * Contract tests for the reusable UI macros (templates/components/**).
 *
 * Twig's `|default` filter fires on ANY empty value - including '' and false - not only on
 * undefined. An option whose empty value is meaningful (`class: ''`, `exact: false`) must therefore
 * be read with `is defined ?` rather than `|default`. Functional tests cannot see a mistake here,
 * because the page still renders, so the behaviour is pinned in this file instead.
 *
 * Anything here asserting on '' or false guards that trap: do not "simplify" the macros back to
 * `|default` for those options.
 */
final class UiMacrosTest extends KernelTestCase
{
    private Environment $twig;

    /** The navigation macros read app.request to resolve the active route, so one is pushed. */
    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = static::getContainer()->get('twig');
        static::getContainer()->get('request_stack')->push(new Request());
    }

    private function render(string $body): string
    {
        return $this->renderWith($body, []);
    }

    /** @param array<string, mixed> $vars */
    private function renderWith(string $body, array $vars): string
    {
        $tpl = "{% import 'components/data/_macros.twig' as d %}"
            ."{% import 'components/navigation/_macros.twig' as nav %}".$body;

        return $this->twig->createTemplate($tpl)->render($vars);
    }

    public function testCardWithoutMarginClassDoesNotGetTheDefaultMargin(): void
    {
        $html = $this->render("{{ d.card_start('T', {class: ''}) }}{{ d.card_end() }}");

        self::assertStringContainsString('class="card"', $html);
        self::assertStringNotContainsString('mb-3', $html, 'class: \'\' must mean NO margin, not the default');
    }

    public function testCardDefaultsToTheStandardMarginWhenClassIsOmitted(): void
    {
        $html = $this->render("{{ d.card_start('T') }}{{ d.card_end() }}");

        self::assertStringContainsString('class="card mb-3"', $html);
    }

    public function testCardCanBeAFormAndCarryLiteralAttributes(): void
    {
        $html = $this->render("{{ d.card_start('T', {tag: 'form', attrs: {method: 'post', action: '/x'}}) }}{{ d.card_end({tag: 'form'}) }}");

        self::assertStringContainsString('<form class="card mb-3" method="post" action="/x">', $html);
        self::assertStringContainsString('</form>', $html);
    }

    public function testCardWithoutBodyClassEmitsNoCardBody(): void
    {
        $html = $this->render("{{ d.card_start('T', {bodyClass: ''}) }}{{ d.card_end({bodyClass: ''}) }}");

        self::assertStringNotContainsString('card-body', $html);
    }

    public function testTableRespectsResponsiveFalseAndHoverFalse(): void
    {
        $html = $this->render('{{ d.table_start({responsive: false, hover: false}) }}{{ d.table_end({responsive: false}) }}');

        self::assertStringNotContainsString('table-responsive', $html, 'responsive: false must drop the wrapper');
        self::assertStringNotContainsString('table-hover', $html, 'hover: false must drop table-hover');
    }

    /**
     * base.html.twig passes exact: false on its nav items so a sub-page keeps the parent
     * highlighted, and |default(true) would swallow that false.
     *
     * With no current request there is no active route, so the macro must still render the link
     * rather than blow up, and must not mark it active.
     */
    public function testNavItemPrefixMatchingIsHonoured(): void
    {
        $tpl = "{% import 'components/navigation/_macros.twig' as nav %}"
            ."{{ nav.nav_item('News', 'app_news_index', {exact: false}) }}";
        $html = $this->twig->createTemplate($tpl)->render();

        self::assertStringContainsString('nav-link', $html);
        self::assertStringNotContainsString('aria-current', $html);
    }

    public function testSidebarItemAcceptsPlainHrefAndForcedActive(): void
    {
        $tpl = "{% import 'components/navigation/_macros.twig' as nav %}"
            ."{{ nav.sidebar_item('Profile', null, {href: '#profile', active: true}) }}";
        $html = $this->twig->createTemplate($tpl)->render();

        self::assertStringContainsString('href="#profile"', $html);
        self::assertStringContainsString('active', $html);
        self::assertStringContainsString('aria-current="page"', $html);
    }

    public function testDeleteFormUsesTheCallerSuppliedTokenAndTheConfirmController(): void
    {
        $html = $this->render("{{ d.delete_form('/things/1/delete', 'TOKEN-FROM-CALLER', {message: 'Delete it?'}) }}");

        self::assertStringContainsString('value="TOKEN-FROM-CALLER"', $html, 'the macro must never mint its own token');
        self::assertStringContainsString('data-controller="confirm"', $html);
        self::assertStringContainsString('data-confirm-message-value="Delete it?"', $html);
        self::assertStringContainsString('method="post"', $html);
    }

    public function testStatusBadgeVocabularyIsExtensibleWithoutForkingTheMacro(): void
    {
        $html = $this->render("{{ d.status_badge('archived', {map: {'archived': 'bg-purple-lt'}}) }}");

        self::assertStringContainsString('bg-purple-lt', $html);
    }

    public function testUserSuppliedTextIsEscaped(): void
    {
        $html = $this->render("{{ d.badge('<script>alert(1)</script>') }}{{ d.empty_inline('<img src=x onerror=1>') }}");

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAvatarFallsBackToAMonogramWhenTheUserHasNoPicture(): void
    {
        $user = (new \App\Entity\User())->setName('Robin Hood');
        $html = $this->renderWith("{{ d.avatar(u, {size: 'xl'}) }}", ['u' => $user]);

        self::assertStringContainsString('avatar avatar-xl', $html);
        self::assertStringContainsString('>RO<', $html, 'first two letters, upper-cased');
        self::assertStringNotContainsString('background-image', $html);
    }

    public function testAvatarUsesTheAuthorizationCheckedMediaRouteWhenAPictureExists(): void
    {
        $user = (new \App\Entity\User())->setName('Robin Hood');
        $personal = new \App\Entity\PersonalData($user);
        $personal->setAvatarPath('avatars/x/y.png');
        $user->setPersonalData($personal);

        $html = $this->renderWith("{{ d.avatar(u, {size: 'sm', class: 'mt-1'}) }}", ['u' => $user]);

        self::assertStringContainsString('avatar avatar-sm mt-1', $html);
        self::assertStringContainsString('/media/avatar/'.$user->getUuid()->toRfc4122(), $html, 'served through app_media_avatar, never hot-linked');
        self::assertStringNotContainsString('avatars/x/y.png', $html, 'the raw storage key never leaves the server');
    }

    public function testCertificationCardRendersTitleDescriptionInfoLinkAndControl(): void
    {
        $html = $this->render(
            "{% set ctrl %}<button>go</button>{% endset %}"
            ."{{ d.certification_card({title: 'First Aid', description: 'Basic aid'}, {infoUrl: '/c/1', control: ctrl}) }}"
        );

        self::assertStringContainsString('First Aid', $html);
        self::assertStringContainsString('Basic aid', $html);
        self::assertStringContainsString('href="/c/1"', $html);
        self::assertStringContainsString('More information', $html);
        self::assertStringContainsString('<button>go</button>', $html, 'the footer control is trusted, captured markup');
    }

    public function testCertificationCardOmitsFooterAndInfoLinkWhenNotRequested(): void
    {
        $html = $this->render("{{ d.certification_card({title: 'Solo', description: ''}) }}");

        self::assertStringContainsString('Solo', $html);
        self::assertStringNotContainsString('card-footer', $html, 'no control and no infoUrl → no footer');
        self::assertStringNotContainsString('More information', $html);
    }

    /** @param array<string, mixed> $options */
    private function renderToast(array $options): string
    {
        return $this->twig
            ->createTemplate("{% import 'components/notification/_macros.twig' as n %}{{ n.toast('t', 'Title', 'Body', options) }}")
            ->render(['options' => $options]);
    }

    /**
     * Bootstrap type-checks `autohide` as a boolean and throws when the attribute carries anything
     * else, and it reads an empty attribute as null and a bare 1 as a number. Both explicit values
     * therefore have to reach the browser as the words, or a sticky toast never constructs.
     */
    public function testToastAutohideIsRenderedAsAWordForBothExplicitValues(): void
    {
        self::assertStringContainsString('data-bs-autohide="false"', $this->renderToast(['autohide' => false]));
        self::assertStringContainsString('data-bs-autohide="true"', $this->renderToast(['autohide' => true]));
    }

    /** Omitting the option leaves a toast that hides itself. */
    public function testToastAutohidesByDefault(): void
    {
        self::assertStringContainsString('data-bs-autohide="true"', $this->renderToast([]));
    }
}
