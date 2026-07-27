<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The user_select picker macro. The `multiple` option must be read with `is defined ?`, not
 * `|default`, so that an explicit `false` (single-user field) is honoured rather than silently
 * treated as the default true - the |default trap the sibling UiMacrosTest guards.
 */
final class UserSelectMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = static::getContainer()->get('twig');
    }

    private function render(string $body): string
    {
        $tpl = "{% import 'components/forms/_macros.twig' as f %}".$body;

        return $this->twig->createTemplate($tpl)->render([]);
    }

    public function testMultipleIsTheDefaultAndSubmitsAnArrayInput(): void
    {
        $html = $this->render("{{ f.user_select('users', {url: '/s', selected: [{id: 'uuid-1', name: 'alice', staff: false}]}) }}");

        self::assertStringContainsString('data-user-select-multiple-value="true"', $html);
        self::assertStringContainsString('name="users[]"', $html);
    }

    public function testSingleModeSubmitsAScalarInputAndFlagsTheController(): void
    {
        $html = $this->render("{{ f.user_select('user', {url: '/s', multiple: false, selected: [{id: 'uuid-1', name: 'alice', staff: false}]}) }}");

        self::assertStringContainsString('data-user-select-multiple-value="false"', $html);
        self::assertStringContainsString('name="user"', $html);
        self::assertStringNotContainsString('name="user[]"', $html, 'a single-user field must not submit an array');
    }
}
