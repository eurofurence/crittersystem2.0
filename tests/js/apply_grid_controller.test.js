import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ApplyGridController from '../../assets/controllers/apply_grid_controller.js';

/*
 * The staff application grid. The dialog is fetched from the server for the shift that was clicked,
 * and a change arriving in one of the departments on screen must never replace the grid while that
 * dialog is open: doing so closes the shift the volunteer is reading.
 */

function markup() {
    return `
        <div data-controller="apply-grid"
             data-apply-grid-detail-url-value="/manage-shifts/apply/shift/__ID__"
             data-apply-grid-grid-url-value="/manage-shifts/apply/grid">
            <form data-apply-grid-target="filters"></form>
            <div data-apply-grid-target="grid"><div class="apply-grid">old</div></div>
            <div class="modal" id="apply-shift-modal">
                <div class="modal-content" data-apply-grid-target="dialog"></div>
            </div>
        </div>`;
}

let application;
let shown;

async function mount() {
    document.body.innerHTML = markup();
    shown = false;
    window.bootstrap = {
        Modal: {
            getOrCreateInstance: () => ({
                show: () => { shown = true; document.querySelector('#apply-shift-modal').classList.add('show'); },
                hide: () => { shown = false; document.querySelector('#apply-shift-modal').classList.remove('show'); },
            }),
        },
    };

    application = Application.start();
    application.register('apply-grid', ApplyGridController);
    await new Promise((resolve) => setTimeout(resolve, 0));

    return application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="apply-grid"]'),
        'apply-grid',
    );
}

/*
 * Emptying the document is what makes Stimulus disconnect the controller, and it notices through a
 * MutationObserver, which runs a tick later. Without that tick the controller keeps its window
 * listener and the next test is served by the previous test's grid as well as its own.
 */
afterEach(async () => {
    document.body.innerHTML = '';
    await new Promise((resolve) => setTimeout(resolve, 0));
    application?.stop();
    delete window.bootstrap;
    vi.unstubAllGlobals();
});

describe('apply grid', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('<div class="apply-grid">fresh</div>', { status: 200 })));
    });

    it('asks the server for the clicked shift and opens the dialog', async () => {
        const controller = await mount();

        await controller.open({ params: { shift: 'shift-uuid' } });

        expect(fetch.mock.calls[0][0]).toBe('/manage-shifts/apply/shift/shift-uuid');
        expect(shown).toBe(true);
        expect(document.querySelector('[data-apply-grid-target="dialog"]').textContent).toContain('fresh');
    });

    it('refreshes the grid when a department it is showing changes', async () => {
        await mount();

        window.dispatchEvent(new CustomEvent('apply-grid:changed'));
        await vi.waitFor(() => expect(document.querySelector('.apply-grid').textContent).toBe('fresh'));
    });

    /*
     * An all-staff shift announces itself on its department's topic and on the all-staff topic, and
     * a member of that department listens to both. That is one change, so it is one request.
     */
    it('refreshes once when the same change is announced on two topics', async () => {
        await mount();

        window.dispatchEvent(new CustomEvent('apply-grid:changed'));
        window.dispatchEvent(new CustomEvent('apply-grid:changed'));
        await vi.waitFor(() => expect(document.querySelector('.apply-grid').textContent).toBe('fresh'));

        expect(fetch.mock.calls.filter(([url]) => url.startsWith('/manage-shifts/apply/grid'))).toHaveLength(1);
    });

    /* Replacing the grid under an open dialog closes the shift the volunteer was reading. */
    it('holds a refresh back while the dialog is open, and applies it once closed', async () => {
        await mount();
        document.querySelector('#apply-shift-modal').classList.add('show');

        window.dispatchEvent(new CustomEvent('apply-grid:changed'));
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(document.querySelector('.apply-grid').textContent).toBe('old');

        document.querySelector('#apply-shift-modal').classList.remove('show');
        document.dispatchEvent(new Event('hidden.bs.modal'));
        await vi.waitFor(() => expect(document.querySelector('.apply-grid').textContent).toBe('fresh'));
    });

    /* A redirected fragment request comes back as the whole page; injecting it would nest the app. */
    it('refuses a full document instead of a fragment', async () => {
        const controller = await mount();
        vi.stubGlobal('fetch', vi.fn(async () => new Response('<html><body>login</body></html>', { status: 200 })));

        await controller.refresh();

        expect(document.querySelector('.apply-grid').textContent).toBe('old');
    });

    it('submits the filter form when a filter changes', async () => {
        const controller = await mount();
        const form = document.querySelector('[data-apply-grid-target="filters"]');
        form.requestSubmit = vi.fn();

        controller.submitFilters();

        expect(form.requestSubmit).toHaveBeenCalled();
    });
});
