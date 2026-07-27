<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The pagination macro. Guards the two things a caller silently depends on: that a single page
 * renders nothing at all, and that page links carry the caller's other query parameters through -
 * without which paging one table on a page would discard the state of every other table on it.
 */
final class PaginationMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = static::getContainer()->get('twig');
    }

    private function render(string $body): string
    {
        $tpl = "{% import 'components/data/_macros.twig' as d %}".$body;

        return $this->twig->createTemplate($tpl)->render([]);
    }

    public function testRendersNothingForASinglePage(): void
    {
        $html = $this->render("{{ d.pagination({route: 'app_departments', page: 1, pages: 1, total: 4}) }}");

        self::assertSame('', trim($html));
    }

    public function testMarksTheCurrentPageAndDisablesPreviousOnTheFirst(): void
    {
        $html = $this->render("{{ d.pagination({route: 'app_departments', page: 1, pages: 5, total: 120, perPage: 25}) }}");

        self::assertStringContainsString('<li class="page-item active">', $html);
        self::assertStringContainsString('aria-disabled="true"', $html);
        self::assertStringContainsString('1-25 of 120', $html);
    }

    /**
     * A disabled control must not be a link. Rendering it as an <a> whose href is page 0 leaves a
     * real, reachable URL behind the "disabled" styling.
     */
    public function testDisabledControlsAreNotLinks(): void
    {
        $first = $this->render("{{ d.pagination({route: 'app_departments', page: 1, pages: 3, total: 60}) }}");
        self::assertStringNotContainsString('page=0', $first);
        self::assertStringContainsString('<span class="page-link" aria-disabled="true">', $first);

        $last = $this->render("{{ d.pagination({route: 'app_departments', page: 3, pages: 3, total: 60}) }}");
        self::assertStringNotContainsString('page=4', $last);
    }

    public function testSummaryReportsTheLastPartialPage(): void
    {
        $html = $this->render("{{ d.pagination({route: 'app_departments', page: 5, pages: 5, total: 118, perPage: 25}) }}");

        self::assertStringContainsString('101-118 of 118', $html);
    }

    public function testPageLinksUseTheGivenParameterAndKeepOtherQueryParameters(): void
    {
        $html = $this->render(
            "{{ d.pagination({route: 'app_departments', param: 'staff_page', page: 2, pages: 4, total: 100, keep: {'managers_q': 'bob', 'nonstaff_page': '3'}}) }}"
        );

        self::assertStringContainsString('staff_page=3', $html);
        self::assertStringContainsString('managers_q=bob', $html, 'another table\'s search must survive paging this one');
        self::assertStringContainsString('nonstaff_page=3', $html, 'another table\'s page must survive paging this one');
    }

    /**
     * A table wrapped in a frame must be marked target="_top" so its row links navigate the whole
     * page; the pager is then the one thing that opts back into the frame. Without `frame` the
     * page links would navigate the whole page too, defeating the frame entirely.
     */
    public function testPageLinksTargetTheGivenFrame(): void
    {
        $html = $this->render("{{ d.pagination({route: 'app_departments', page: 2, pages: 4, total: 100, frame: 'dept-members-staff'}) }}");

        // Every page link, and only page links, carries the target.
        self::assertSame(
            substr_count($html, '<a class="page-link"'),
            substr_count($html, 'data-turbo-frame="dept-members-staff"'),
        );
        self::assertGreaterThan(0, substr_count($html, 'data-turbo-frame="dept-members-staff"'));
    }

    public function testFrameTargetingIsOmittedWhenNoFrameIsGiven(): void
    {
        $html = $this->render("{{ d.pagination({route: 'app_departments', page: 2, pages: 4, total: 100}) }}");

        self::assertStringNotContainsString('data-turbo-frame', $html);
    }

    public function testLongRunsAreWindowedWithEllipses(): void
    {
        $html = $this->render("{{ d.pagination({route: 'app_departments', page: 10, pages: 40, total: 1000}) }}");

        self::assertStringContainsString('&hellip;', $html);
        self::assertStringNotContainsString('>20</a>', $html, 'a far-away page must not be listed');
        self::assertStringContainsString('>40</a>', $html, 'the last page stays reachable');
    }
}
