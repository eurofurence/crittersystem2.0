import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ShiftGroupController from '../../assets/controllers/shift_group_controller.js';

/*
 * Applying to a shift that belongs to a group commits the volunteer to every shift in it, so the
 * submit must not go through until the dialog has shown them what that is. These tests protect the
 * three ways that could silently fail: submitting without the dialog, submitting while a required
 * role is still unchosen, and leaving a modal backdrop behind that locks the next page.
 *
 * The submit handler is invoked directly: happy-dom does not deliver a synthetic submit to a
 * Stimulus binding, and the wiring itself is what the Browser suite drives in real Chrome.
 */

const BODY_WITH_CHOICE = `
    <div class="shift-group-modal">
        <select name="group_type[11111111-1111-4111-8111-111111111111]" required>
            <option value="">Choose a role</option>
            <option value="4">Steward</option>
        </select>
    </div>
    <div data-shift-group-meta data-applicable="1" data-count="2" hidden></div>`;

const BODY_READY = `
    <div class="shift-group-modal">
        <input type="hidden" name="group_type[11111111-1111-4111-8111-111111111111]" value="4">
        <textarea name="comment"></textarea>
    </div>
    <div data-shift-group-meta data-applicable="1" data-count="2" hidden></div>`;

function markup() {
    return `
        <form id="f" method="post" action="/shifts/abc/signup"
              data-controller="shift-group"
              data-shift-group-url-value="/shifts/abc/group"
              data-shift-group-confirm-label-value="Apply to all 2 shifts">
            <input type="hidden" name="_token" value="tok">
            <select name="volunteer_type"><option value="4" selected>Steward</option></select>
            <button type="submit">Apply</button>
        </form>`;
}

let application;
let controller;
let submitted;

/** A minimal stand-in for the Bootstrap modal the real page loads. */
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

async function start(body = BODY_READY) {
    document.body.innerHTML = markup();
    vi.stubGlobal('fetch', vi.fn(async () => new Response(body, { status: 200 })));
    stubBootstrap();

    application = Application.start();
    application.register('shift-group', ShiftGroupController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('#f'),
        'shift-group',
    );

    submitted = false;
    const form = document.querySelector('#f');
    form.requestSubmit = () => { submitted = true; };
    form.submit = () => { submitted = true; };
}

function submitEvent() {
    const event = new Event('submit', { cancelable: true });
    return event;
}

describe('shift group confirmation', () => {
    afterEach(() => {
        application?.stop();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('does not submit until the dialog has been confirmed', async () => {
        await start();

        await controller.onSubmit(submitEvent());

        expect(submitted).toBe(false);
        expect(document.querySelector('.modal')).not.toBeNull();
    });

    it('fetches the dialog body from the server rather than building it', async () => {
        await start();

        await controller.onSubmit(submitEvent());

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(String(fetch.mock.calls[0][0])).toContain('/shifts/abc/group');
    });

    it('keeps the confirm button disabled while a role is unchosen', async () => {
        await start(BODY_WITH_CHOICE);

        await controller.onSubmit(submitEvent());

        const confirm = document.querySelector('[data-role="confirm"]');
        expect(confirm.disabled).toBe(true);

        document.querySelector('.modal select[name^="group_type"]').value = '4';
        controller.syncConfirmState();
        expect(confirm.disabled).toBe(false);
    });

    it('carries the dialog choices onto the form and submits once confirmed', async () => {
        await start();

        await controller.onSubmit(submitEvent());
        document.querySelector('[data-role="confirm"]').click();

        expect(submitted).toBe(true);
        const carried = document.querySelector('#f input[data-shift-group-field]');
        expect(carried).not.toBeNull();
        expect(carried.name).toBe('group_type[11111111-1111-4111-8111-111111111111]');
    });

    it('refuses to submit when the dialog body cannot be loaded', async () => {
        await start();
        vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 500 })));

        // The failure is reported through a notice the user has to dismiss, so the pending dialog is
        // only awaited after that notice is acknowledged.
        const pending = controller.onSubmit(submitEvent());
        await new Promise((resolve) => setTimeout(resolve, 0));
        document.querySelector('.modal [data-role="confirm"]').click();
        await pending;

        expect(submitted).toBe(false);
    });

    it('offers the footer links the server supplied, and only those', async () => {
        await start();
        controller.detailUrlValue = '/shifts/abc';
        controller.detailLabelValue = 'Open shift page';

        await controller.onSubmit(submitEvent());

        const links = document.querySelectorAll('.modal [data-role="links"] a');
        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('href')).toBe('/shifts/abc');
        expect(links[0].textContent).toBe('Open shift page');
    });

    it('opens a confirmable dialog from a link, since the card has no submit button', async () => {
        await start();

        await controller.trigger(submitEvent());

        expect(submitted).toBe(false);
        expect(document.querySelector('.modal [data-role="confirm"]')).not.toBeNull();

        document.querySelector('[data-role="confirm"]').click();
        expect(submitted).toBe(true);
    });

    it('leaves no modal or backdrop behind on disconnect', async () => {
        await start();

        await controller.onSubmit(submitEvent());
        document.body.insertAdjacentHTML('beforeend', '<div class="modal-backdrop"></div>');

        controller.disconnect();

        expect(document.querySelector('.modal')).toBeNull();
        expect(document.querySelector('.modal-backdrop')).toBeNull();
        expect(document.body.classList.contains('modal-open')).toBe(false);
    });
});
