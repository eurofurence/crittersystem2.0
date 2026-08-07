import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ScrollNavController from '../../assets/controllers/scroll_nav_controller.js';

/*
 * The arrows that tell a manager a wide grid scrolls sideways. A significant number of them never
 * discovered the planner had more days than the ones on screen, so an arrow that fails to appear is
 * the whole defect this controller exists for.
 */

function markup() {
    return `
        <div data-controller="scroll-nav">
            <button data-scroll-nav-target="prev" data-action="scroll-nav#prev" hidden></button>
            <button data-scroll-nav-target="next" data-action="scroll-nav#next" hidden></button>
            <div id="scroller" data-scroll-nav-target="scroller"></div>
        </div>`;
}

let application;

/** jsdom lays nothing out, so the scroll geometry is described rather than measured. */
function size(scroller, { scrollLeft = 0, clientWidth = 500, scrollWidth = 2000 } = {}) {
    Object.defineProperty(scroller, 'clientWidth', { value: clientWidth, configurable: true });
    Object.defineProperty(scroller, 'scrollWidth', { value: scrollWidth, configurable: true });
    scroller.scrollLeft = scrollLeft;
}

async function mount(geometry = {}) {
    document.body.innerHTML = markup();
    size(document.querySelector('#scroller'), geometry);

    application = Application.start();
    application.register('scroll-nav', ScrollNavController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    return application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="scroll-nav"]'),
        'scroll-nav',
    );
}

beforeEach(() => {
    vi.stubGlobal('ResizeObserver', class {
        observe() {}
        unobserve() {}
        disconnect() {}
    });
});

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('scroll nav', () => {
    it('offers only the forward arrow at the start of the range', async () => {
        await mount();

        expect(document.querySelector('[data-scroll-nav-target="prev"]').hidden).toBe(true);
        expect(document.querySelector('[data-scroll-nav-target="next"]').hidden).toBe(false);
    });

    it('offers both arrows in the middle', async () => {
        const controller = await mount({ scrollLeft: 400 });
        controller.update();

        expect(document.querySelector('[data-scroll-nav-target="prev"]').hidden).toBe(false);
        expect(document.querySelector('[data-scroll-nav-target="next"]').hidden).toBe(false);
    });

    it('drops the forward arrow at the end, so it also reads as "this is the last day"', async () => {
        const controller = await mount({ scrollLeft: 1500 });
        controller.update();

        expect(document.querySelector('[data-scroll-nav-target="next"]').hidden).toBe(true);
        expect(document.querySelector('[data-scroll-nav-target="prev"]').hidden).toBe(false);
    });

    it('hides both when everything already fits', async () => {
        await mount({ scrollWidth: 500 });

        expect(document.querySelector('[data-scroll-nav-target="prev"]').hidden).toBe(true);
        expect(document.querySelector('[data-scroll-nav-target="next"]').hidden).toBe(true);
    });

    it('scrolls by most of a screenful in the direction pressed', async () => {
        const controller = await mount();
        const scroller = document.querySelector('#scroller');
        scroller.scrollBy = vi.fn();

        controller.next();
        expect(scroller.scrollBy).toHaveBeenCalledWith({ left: 400, behavior: 'smooth' });

        controller.prev();
        expect(scroller.scrollBy).toHaveBeenCalledWith({ left: -400, behavior: 'smooth' });
    });

    /* The planner replaces its grid wholesale on every edit, so the arrows must find the new one. */
    it('follows the scroll container when the region is replaced', async () => {
        const controller = await mount({ scrollLeft: 1500 });
        controller.update();
        expect(document.querySelector('[data-scroll-nav-target="next"]').hidden).toBe(true);

        const replacement = document.createElement('div');
        replacement.dataset.scrollNavTarget = 'scroller';
        size(replacement);
        document.querySelector('#scroller').replaceWith(replacement);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.querySelector('[data-scroll-nav-target="next"]').hidden).toBe(false);
    });
});
