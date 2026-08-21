import { describe, it, expect, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import PresentationController from '../../assets/controllers/presentation_controller.js';

/*
 * Presentation mode is the one part of the statistics dashboard with no server-rendered fallback:
 * if the controller leaves the page stuck in large type, or fails when the browser refuses
 * fullscreen, the dashboard is unusable on the machine driving the projector.
 */

function markup() {
    return `
        <div class="statistics-board" data-controller="presentation"
             data-presentation-enter-label-value="Present"
             data-presentation-exit-label-value="Leave">
            <button data-action="presentation#toggle">
                <span data-presentation-target="label">Present</span>
            </button>
        </div>`;
}

let application;

async function start({ fullscreenAvailable = true, fullscreenRejects = false } = {}) {
    document.body.innerHTML = markup();

    const board = document.querySelector('.statistics-board');
    if (fullscreenAvailable) {
        board.requestFullscreen = vi.fn(() =>
            fullscreenRejects ? Promise.reject(new Error('denied')) : Promise.resolve(),
        );
    } else {
        board.requestFullscreen = undefined;
    }

    application = Application.start();
    application.register('presentation', PresentationController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    return board;
}

const label = () => document.querySelector('[data-presentation-target="label"]').textContent;

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
    Object.defineProperty(document, 'fullscreenElement', { value: null, configurable: true, writable: true });
});

describe('presentation mode', () => {
    it('enters and leaves on the button', async () => {
        const board = await start();

        board.querySelector('button').click();
        expect(board.classList.contains('is-presenting')).toBe(true);
        expect(document.body.classList.contains('stats-presenting')).toBe(true);
        expect(label()).toBe('Leave');
        expect(board.requestFullscreen).toHaveBeenCalled();

        board.querySelector('button').click();
        expect(board.classList.contains('is-presenting')).toBe(false);
        expect(document.body.classList.contains('stats-presenting')).toBe(false);
        expect(label()).toBe('Present');
    });

    /* Escape leaves fullscreen without a click, and the layout has to follow it back. */
    it('leaves the mode when the browser exits fullscreen', async () => {
        const board = await start();
        board.querySelector('button').click();
        expect(board.classList.contains('is-presenting')).toBe(true);

        Object.defineProperty(document, 'fullscreenElement', { value: null, configurable: true, writable: true });
        document.dispatchEvent(new Event('fullscreenchange'));

        expect(board.classList.contains('is-presenting')).toBe(false);
        expect(label()).toBe('Present');
    });

    /* A browser that refuses or lacks fullscreen still gets the large-type layout. */
    it('still enlarges when fullscreen is rejected', async () => {
        const board = await start({ fullscreenRejects: true });

        board.querySelector('button').click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(board.classList.contains('is-presenting')).toBe(true);
    });

    it('still enlarges when fullscreen is unavailable', async () => {
        const board = await start({ fullscreenAvailable: false });

        board.querySelector('button').click();

        expect(board.classList.contains('is-presenting')).toBe(true);
    });

    /* Turbo swaps the body without tearing the document down, so disconnect must clean up. */
    it('releases the document listener and body class on disconnect', async () => {
        const board = await start();
        board.querySelector('button').click();
        expect(document.body.classList.contains('stats-presenting')).toBe(true);

        application.getControllerForElementAndIdentifier(board, 'presentation').disconnect();

        expect(document.body.classList.contains('stats-presenting')).toBe(false);
        expect(board.classList.contains('is-presenting')).toBe(false);
    });
});
