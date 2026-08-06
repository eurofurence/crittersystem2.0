import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import PlannerController from '../../assets/controllers/planner_controller.js';

/*
 * The planner controller, driven through a real Stimulus Application so the framework's own
 * lifecycle order applies. That order is the point: Stimulus fires the *TargetConnected callbacks
 * BEFORE connect(), and a controller that set its state up in connect() threw on the first block of
 * a populated grid - which aborted the connection and took painting, dragging and the grid refresh
 * down with it. Rendering markup in PHPUnit could never see that.
 */

const BLOCK = (uuid, top = 10, height = 5) => `
    <div class="planner-block"
         data-planner-target="block"
         data-shift-id="${uuid}"
         data-start="2026-06-01T10:00:00+00:00"
         data-move-url="/move/${uuid}"
         data-delete-url="/delete/${uuid}"
         style="top: ${top}%; height: ${height}%; left: 0%; width: calc(100% - 4px);"></div>`;

function markup(blocks = '') {
    return `
        <div id="planner-publish-bar"><span>2 drafts</span></div>
        <div class="planner"
             data-controller="planner"
             data-planner-department-value="dept-uuid"
             data-planner-raster-value="30"
             data-planner-paint-url-value="/paint"
             data-planner-paint-token-value="tok"
             data-planner-edit-token-value="tok">
            <div class="planner-grid" data-planner-target="grid">
                <div class="planner-day-body" data-planner-target="dayBody" data-planner-day="2026-06-01">
                    ${blocks}
                </div>
            </div>
        </div>`;
}

let application;
let controllerErrors;

async function start(blocks = '') {
    document.body.innerHTML = markup(blocks);
    application = Application.start();
    /*
     * Stimulus routes every controller exception to Application#handleError and keeps going, so a
     * crash leaves no trace a test would trip over - it just silently stops connecting. Capturing
     * that hook is what turns "the controller threw" into a failing assertion. console.error is not
     * enough: the default handler did not reach it here.
     */
    application.handleError = (error, message) => controllerErrors.push(`${message}: ${error.message}`);
    application.register('planner', PlannerController);
    // Let Stimulus connect its controllers.
    await new Promise((resolve) => setTimeout(resolve, 0));

    return application.getControllerForElementAndIdentifier(
        document.querySelector('.planner'),
        'planner',
    );
}

describe('planner controller', () => {
    let onRejection;

    beforeEach(() => {
        /*
         * Stimulus wraps initialize/connect in its error handler but NOT the *TargetConnected
         * callbacks, so a throw there escapes as an unhandled rejection - which is precisely how the
         * production crash presented ("Uncaught (in promise) TypeError") and why neither
         * console.error nor Application#handleError saw it. Both paths are watched here.
         */
        controllerErrors = [];
        onRejection = (reason) => controllerErrors.push(`unhandled: ${reason?.message ?? reason}`);
        process.on('unhandledRejection', onRejection);
    });

    afterEach(() => {
        process.off('unhandledRejection', onRejection);
        application?.stop();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('connects without error on a grid that already contains blocks', async () => {
        const controller = await start(BLOCK('uuid-a') + BLOCK('uuid-b', 30));

        expect(controllerErrors).toEqual([]);
        expect(controller).toBeTruthy();
        expect(controller.blockTargets).toHaveLength(2);
    });

    it('has its state ready before Stimulus connects the targets', async () => {
        const controller = await start(BLOCK('uuid-a'));

        // The regression guard: both sets are read by blockTargetConnected, which runs first.
        expect(controller.invalid).toBeInstanceOf(Set);
        expect(controller.selected).toBeInstanceOf(Set);
    });

    describe('drag modes', () => {
        it('selects rather than creating when dragging empty grid in select mode', async () => {
            const controller = await start();
            const body = document.querySelector('.planner-day-body');

            body.dispatchEvent(new window.PointerEvent('pointerdown', { button: 0, bubbles: true }));

            expect(controller.gesture?.kind).toBe('marquee');
            expect(document.querySelector('.planner-paint-preview')).toBeNull();
        });

        it('paints on empty grid in paint mode', async () => {
            const controller = await start();
            controller.modeValue = 'paint';
            const body = document.querySelector('.planner-day-body');

            body.dispatchEvent(new window.PointerEvent('pointerdown', { button: 0, bubbles: true }));

            expect(controller.gesture?.kind).toBe('paint');
            expect(document.querySelector('.planner-paint-preview')).not.toBeNull();
        });

        /* The reported bug: a drag starting on a block used to move it, so a parallel shift could
           not be created at all. */
        it('paints over an existing block in paint mode rather than moving it', async () => {
            const controller = await start(BLOCK('uuid-a'));
            controller.modeValue = 'paint';
            const block = document.querySelector('.planner-block');

            block.dispatchEvent(new window.PointerEvent('pointerdown', { button: 0, bubbles: true }));

            expect(controller.gesture?.kind).toBe('paint');
            expect(block.classList.contains('is-dragging')).toBe(false);
        });

        /* Pressing a block only arms a move: it becomes one once the pointer actually travels. */
        it('arms a move when pressing a block in select mode', async () => {
            const controller = await start(BLOCK('uuid-a'));
            const block = document.querySelector('.planner-block');

            block.dispatchEvent(new window.PointerEvent('pointerdown', { button: 0, bubbles: true }));

            expect(controller.gesture?.kind).toBe('move');
            expect(controller.gesture?.armed).toBe(false);
            expect(block.classList.contains('is-dragging')).toBe(false);
        });

        it('reflects the mode on the element so the cursor can follow it', async () => {
            const controller = await start();
            // Stimulus propagates value changes through a MutationObserver, so the callback that
            // toggles the class lands on the next tick rather than on assignment.
            const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

            controller.modeValue = 'paint';
            await tick();
            expect(controller.element.classList.contains('is-painting')).toBe(true);

            controller.modeValue = 'select';
            await tick();
            expect(controller.element.classList.contains('is-painting')).toBe(false);
        });
    });

    /*
     * The bug managers reported as "selecting a shift is almost impossible, it takes several tries".
     * A press on a block started a move outright, and any pointer movement at all - a pixel of
     * tremor under an ordinary click - posted that move and reloaded the grid. The click that
     * followed then landed on a block the reload had already detached, so the selection went
     * nowhere.
     */
    describe('selecting', () => {
        function measure() {
            const body = document.querySelector('.planner-day-body');
            body.getBoundingClientRect = () => ({ top: 0, left: 0, height: 1440, width: 200, right: 200, bottom: 1440 });
        }

        function press(element, clientY, init = {}) {
            element.dispatchEvent(new window.PointerEvent('pointerdown', { button: 0, bubbles: true, clientX: 10, clientY, ...init }));
        }

        function moveTo(clientY, clientX = 10) {
            window.dispatchEvent(new window.PointerEvent('pointermove', { clientX, clientY }));
        }

        function release() {
            window.dispatchEvent(new window.PointerEvent('pointerup', {}));
        }

        beforeEach(() => {
            vi.stubGlobal('fetch', vi.fn(async () => new Response('<html><body></body></html>', { status: 200 })));
        });

        it('selects the block when a click wobbles a pixel, and saves nothing', async () => {
            const controller = await start(BLOCK('uuid-a'));
            measure();
            const block = document.querySelector('.planner-block');

            press(block, 150);
            moveTo(152, 11);
            release();

            expect([...controller.selected]).toEqual(['uuid-a']);
            expect(block.classList.contains('is-selected')).toBe(true);
            expect(fetch).not.toHaveBeenCalled();
        });

        it('moves the block when the pointer really travels', async () => {
            await start(BLOCK('uuid-a'));
            measure();
            const block = document.querySelector('.planner-block');

            press(block, 150);
            moveTo(300);
            release();

            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
            expect(fetch.mock.calls[0][0]).toBe('/move/uuid-a');
        });

        it('saves nothing when a drag ends where it started', async () => {
            const controller = await start(BLOCK('uuid-a'));
            measure();
            const block = document.querySelector('.planner-block');

            press(block, 150);
            moveTo(300);
            moveTo(150);
            release();

            expect(fetch).not.toHaveBeenCalled();
            expect(controller.selected.size).toBe(0);
        });

        it('adds to the selection with shift held, and replaces it without', async () => {
            const controller = await start(BLOCK('uuid-a') + BLOCK('uuid-b', 30));
            measure();
            const [a, b] = document.querySelectorAll('.planner-block');

            press(a, 150);
            release();
            press(b, 450, { shiftKey: true });
            release();
            expect([...controller.selected].sort()).toEqual(['uuid-a', 'uuid-b']);

            press(a, 150);
            release();
            expect([...controller.selected]).toEqual(['uuid-a']);
        });

        it('clears the selection when clicking empty grid', async () => {
            const controller = await start(BLOCK('uuid-a'));
            measure();

            press(document.querySelector('.planner-block'), 150);
            release();
            expect(controller.selected.size).toBe(1);

            press(document.querySelector('.planner-day-body'), 800);
            release();
            expect(controller.selected.size).toBe(0);
        });

        /* Windows-explorer style: drag a band over empty grid and take everything it touches. */
        it('selects every block the rubber band touches', async () => {
            const controller = await start(BLOCK('uuid-a') + BLOCK('uuid-b', 30));
            measure();
            const [a, b] = document.querySelectorAll('.planner-block');
            a.getBoundingClientRect = () => ({ top: 140, bottom: 200, left: 0, right: 100 });
            b.getBoundingClientRect = () => ({ top: 420, bottom: 480, left: 0, right: 100 });

            press(document.querySelector('.planner-day-body'), 100);
            moveTo(460, 50);

            expect(document.querySelector('.planner-marquee')).not.toBeNull();
            expect([...controller.selected].sort()).toEqual(['uuid-a', 'uuid-b']);

            release();
            expect(document.querySelector('.planner-marquee')).toBeNull();
        });
    });

    describe('invalid shift outlines', () => {
        it('outlines the shifts a publish attempt rejected', async () => {
            await start(BLOCK('uuid-a') + BLOCK('uuid-b', 30));

            window.dispatchEvent(new window.CustomEvent('planner:invalid', { detail: { uuids: ['uuid-b'] } }));

            const [a, b] = document.querySelectorAll('.planner-block');
            expect(a.classList.contains('planner-block-invalid')).toBe(false);
            expect(b.classList.contains('planner-block-invalid')).toBe(true);
        });

        it('clears the outlines when handed an empty set', async () => {
            await start(BLOCK('uuid-a'));

            window.dispatchEvent(new window.CustomEvent('planner:invalid', { detail: { uuids: ['uuid-a'] } }));
            expect(document.querySelector('.planner-block').classList.contains('planner-block-invalid')).toBe(true);

            window.dispatchEvent(new window.CustomEvent('planner:invalid', { detail: { uuids: [] } }));
            expect(document.querySelector('.planner-block').classList.contains('planner-block-invalid')).toBe(false);
        });

        /*
         * The outlines exist so a manager can find the remaining problems after fixing one. The grid
         * is replaced wholesale on every edit, so they must be re-applied to the incoming blocks.
         */
        it('re-applies the outlines to blocks that arrive with a reloaded grid', async () => {
            const controller = await start(BLOCK('uuid-a'));
            window.dispatchEvent(new window.CustomEvent('planner:invalid', { detail: { uuids: ['uuid-a', 'uuid-c'] } }));

            const body = document.querySelector('.planner-day-body');
            body.insertAdjacentHTML('beforeend', BLOCK('uuid-c', 50));
            await new Promise((resolve) => setTimeout(resolve, 0));

            const fresh = document.querySelector('[data-shift-id="uuid-c"]');
            expect(fresh.classList.contains('planner-block-invalid')).toBe(true);
            expect(controller.invalid.has('uuid-c')).toBe(true);
        });
    });

    describe('grid reload', () => {
        /*
         * The draft counter and publish button live outside the grid. Refreshing only the grid left
         * them describing the state from before the edit, so after a successful publish the button
         * stayed live over zero drafts and the next click answered "no draft shifts to publish".
         */
        it('refreshes the publish bar alongside the grid', async () => {
            const controller = await start(BLOCK('uuid-a'));

            const fresh = `<html><body>
                <div id="planner-publish-bar"><span>0 drafts</span><button disabled></button></div>
                <div class="planner-grid" data-planner-target="grid">
                    <div class="planner-day-body" data-planner-target="dayBody" data-planner-day="2026-06-01"></div>
                </div>
            </body></html>`;
            vi.stubGlobal('fetch', vi.fn(async () => new Response(fresh, { status: 200 })));

            await controller.reloadGrid();

            const bar = document.querySelector('#planner-publish-bar');
            expect(bar.textContent).toContain('0 drafts');
            expect(bar.querySelector('button').hasAttribute('disabled')).toBe(true);
        });

        /*
         * A refresh landing mid-drag used to leave the gesture pointing at a block that had just
         * been detached, and its pointerup saved a position computed against that dead element: the
         * shift jumped to an unrelated time on its own.
         */
        it('cancels a drag in progress rather than saving a position from the replaced grid', async () => {
            const controller = await start(BLOCK('uuid-a'));
            const body = document.querySelector('.planner-day-body');
            body.getBoundingClientRect = () => ({ top: 0, left: 0, height: 1440, width: 200, right: 200, bottom: 1440 });

            const fresh = `<html><body>
                <div class="planner-grid" data-planner-target="grid">
                    <div class="planner-day-body" data-planner-target="dayBody" data-planner-day="2026-06-01">
                        ${BLOCK('uuid-a')}
                    </div>
                </div>
            </body></html>`;
            vi.stubGlobal('fetch', vi.fn(async () => new Response(fresh, { status: 200 })));

            document.querySelector('.planner-block')
                .dispatchEvent(new window.PointerEvent('pointerdown', { button: 0, bubbles: true, clientX: 10, clientY: 150 }));
            window.dispatchEvent(new window.PointerEvent('pointermove', { clientX: 10, clientY: 600 }));

            await controller.reloadGrid();
            window.dispatchEvent(new window.PointerEvent('pointerup', {}));

            expect(controller.gesture).toBeNull();
            expect(fetch.mock.calls.some(([url]) => String(url).startsWith('/move/'))).toBe(false);
        });

        /* Losing the selection on every refresh is what made the planner unusable in a busy department. */
        it('keeps the selection across a reload and drops shifts that are gone', async () => {
            const controller = await start(BLOCK('uuid-a') + BLOCK('uuid-b', 30));
            controller.replaceSelection(['uuid-a', 'uuid-b']);

            const fresh = `<html><body>
                <div class="planner-grid" data-planner-target="grid">
                    <div class="planner-day-body" data-planner-target="dayBody" data-planner-day="2026-06-01">
                        ${BLOCK('uuid-a')}
                    </div>
                </div>
            </body></html>`;
            vi.stubGlobal('fetch', vi.fn(async () => new Response(fresh, { status: 200 })));

            await controller.reloadGrid();
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect([...controller.selected]).toEqual(['uuid-a']);
            expect(document.querySelector('[data-shift-id="uuid-a"]').classList.contains('is-selected')).toBe(true);
        });
    });
});
