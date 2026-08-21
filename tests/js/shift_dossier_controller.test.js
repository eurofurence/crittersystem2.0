import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ShiftDossierController from '../../assets/controllers/shift_dossier_controller.js';

/*
 * The dossier dialog fetches its body from the server on every open. These tests protect the three
 * ways that silently breaks: building the body in the browser instead of asking for it, leaving a
 * modal backdrop behind that locks the next page after a Turbo navigation, and swallowing a failed
 * fetch so the operator sees an empty dialog rather than being told to retry.
 */

const BODY = '<div class="dossier">Created by planner-jo</div>';

function markup() {
    return `
        <a id="t" href="/shifts/abc"
           data-controller="shift-dossier"
           data-shift-dossier-url-value="/shifts/abc/info"
           data-shift-dossier-title-value="Morning Gate"></a>`;
}

let application;
let controller;

function stubBootstrap() {
    const instances = new WeakMap();
    vi.stubGlobal('bootstrap', {
        Modal: {
            getOrCreateInstance(el) {
                if (!instances.has(el)) {
                    instances.set(el, {
                        show: vi.fn(),
                        hide: vi.fn(() => el.dispatchEvent(new Event('hidden.bs.modal'))),
                        dispose: vi.fn(),
                    });
                }

                return instances.get(el);
            },
        },
    });
}

async function start({ ok = true } = {}) {
    document.body.innerHTML = markup();
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(ok ? BODY : 'boom', { status: ok ? 200 : 500 })),
    );
    stubBootstrap();

    application = Application.start();
    application.register('shift-dossier', ShiftDossierController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('#t'),
        'shift-dossier',
    );
}

describe('shift_dossier_controller', () => {
    beforeEach(() => {
        document.body.className = '';
    });

    afterEach(() => {
        if (application) {
            application.stop();
        }
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('asks the server for the body instead of building it', async () => {
        await start();
        await controller.open(new Event('click'));

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch.mock.calls[0][0]).toBe('/shifts/abc/info');
        expect(document.querySelector('.modal .modal-body').innerHTML).toContain('planner-jo');
        expect(document.querySelector('.modal .modal-title').textContent).toBe('Morning Gate');
    });

    it('does not navigate when it opens the dialog', async () => {
        await start();
        const event = new Event('click', { cancelable: true });
        await controller.open(event);

        expect(event.defaultPrevented).toBe(true);
    });

    /*
     * Bootstrap leaves the backdrop behind when a modal is disposed mid-transition. Turbo replaces
     * the body without tearing the document down, so a leftover backdrop covers the next page with
     * an invisible overlay that nothing can dismiss.
     */
    it('removes the dialog and any backdrop when it disconnects', async () => {
        await start();
        await controller.open(new Event('click'));
        document.body.appendChild(Object.assign(document.createElement('div'), { className: 'modal-backdrop' }));
        document.body.classList.add('modal-open');

        controller.disconnect();

        expect(document.querySelector('.modal')).toBeNull();
        expect(document.querySelector('.modal-backdrop')).toBeNull();
        expect(document.body.classList.contains('modal-open')).toBe(false);
    });

    /*
     * The notice resolves only when the operator dismisses it, so open() is deliberately not
     * awaited here: awaiting a dialog nobody can click would hang the test rather than fail it.
     */
    it('says so when the body cannot be loaded, rather than opening an empty dialog', async () => {
        await start({ ok: false });
        controller.open(new Event('click'));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.body.textContent).toContain('could not be loaded');
        expect(document.body.textContent).not.toContain('planner-jo');
    });
});
