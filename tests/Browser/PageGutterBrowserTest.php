<?php

namespace App\Tests\Browser;

use Facebook\WebDriver\WebDriverDimension;

/**
 * The page keeps both of its edges still when a vertical scrollbar appears.
 *
 * Tabler reserves the scrollbar gutter above 992px by shifting the whole document right with
 * `:root { margin-left: calc(100vw - 100%) }`, which is correct only for a centered container. Every
 * layout here is `layout-fluid`, so that margin used to open an empty strip one scrollbar wide down
 * the left of the navbar and the page. `assets/styles/app.css` cancels it and reserves the gutter on
 * the scroll container instead.
 *
 * Only a real browser can see this: the server sends identical markup either way, and the defect
 * exists purely in what the layout engine does with the viewport width.
 */
final class PageGutterBrowserTest extends BrowserTestCase
{
    private const FORCE_OVERFLOW = <<<'JS'
        const filler = document.createElement('div');
        filler.id = 'gutter-test-filler';
        filler.style.height = '3000px';
        document.body.appendChild(filler);
        JS;

    private const MEASURE = <<<'JS'
        const bar = document.querySelector('header.navbar').getBoundingClientRect();
        return {
            left: Math.round(bar.left * 10) / 10,
            right: Math.round(bar.right * 10) / 10,
            htmlMarginLeft: getComputedStyle(document.documentElement).marginLeft,
            gutter: window.innerWidth - document.documentElement.clientWidth,
            overflowsVertically: document.documentElement.scrollHeight > document.documentElement.clientHeight,
        };
        JS;

    public function testAScrollbarNeitherDentsNorMovesThePage(): void
    {
        $this->openLoginAt(1400, 1000);

        $short = $this->client->executeScript(self::MEASURE);
        self::assertFalse(
            $short['overflowsVertically'],
            'this test compares a page that scrolls against one that does not, so the login page must fit a 1000px window; enlarge the window here if the page has grown',
        );

        $this->client->executeScript(self::FORCE_OVERFLOW);
        $scrolled = $this->client->executeScript(self::MEASURE);

        if (0 === $scrolled['gutter']) {
            self::markTestSkipped('this browser draws overlay scrollbars, which take up no layout space and cannot reproduce the defect');
        }

        self::assertSame('0px', $scrolled['htmlMarginLeft'], 'the document must not be pushed right to make room for the scrollbar');
        self::assertSame(0.0, (float) $scrolled['left'], 'a scrollbar must not open a gap on the left of the page');
        self::assertSame((float) $short['left'], (float) $scrolled['left'], 'the left edge must not move when the page starts scrolling');
        self::assertSame((float) $short['right'], (float) $scrolled['right'], 'the right edge must not move when the page starts scrolling');
    }

    /**
     * Bootstrap pads the body by the scrollbar width whenever a dialog puts `overflow: hidden` on it,
     * to replace the space a vanishing scrollbar gives back. The reserved gutter never gives that
     * space back, so the padding has to stay flat or every dialog drags the page sideways while it is
     * open. The dialog is built here rather than borrowed from a page so the guarantee is tested
     * wherever modals are used, not only on the one screen that happens to have one.
     */
    public function testOpeningADialogDoesNotDragThePageSideways(): void
    {
        $this->openLoginAt(1400, 1000);
        $this->client->executeScript(self::FORCE_OVERFLOW);

        $before = $this->client->executeScript(self::MEASURE);

        if (0 === $before['gutter']) {
            self::markTestSkipped('this browser draws overlay scrollbars, which take up no layout space and cannot reproduce the defect');
        }

        $this->client->executeScript(<<<'JS'
            const dialog = document.createElement('div');
            dialog.className = 'modal fade';
            dialog.id = 'gutter-test-modal';
            dialog.tabIndex = -1;
            dialog.innerHTML = '<div class="modal-dialog"><div class="modal-content"><div class="modal-body">gutter</div></div></div>';
            document.body.appendChild(dialog);
            new window.bootstrap.Modal(dialog).show();
            JS);
        $this->client->waitForVisibility('#gutter-test-modal.show', 10);

        $open = $this->client->executeScript(self::MEASURE);

        self::assertSame((float) $before['left'], (float) $open['left'], 'an open dialog must not move the left edge of the page');
        self::assertSame((float) $before['right'], (float) $open['right'], 'an open dialog must not move the right edge of the page');
    }

    private function openLoginAt(int $width, int $height): void
    {
        $this->browse();
        $this->client->getWebDriver()->manage()->window()->setSize(new WebDriverDimension($width, $height));

        $this->client->request('GET', '/login');
        $this->client->waitFor('header.navbar', 10);
    }
}
