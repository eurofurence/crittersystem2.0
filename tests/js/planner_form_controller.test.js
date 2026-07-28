import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import PlannerFormController from '../../assets/controllers/planner_form_controller.js';

/*
 * Planner forms are submitted from a button's click rather than the form's submit event, so the
 * browser never runs its own constraint checking on them. Without an explicit check a required
 * field - the shift task, which the server now refuses to save without - posts empty and comes back
 * as a generic error instead of pointing at the field that is wrong.
 *
 * The action is invoked directly: happy-dom does not deliver a synthetic click to a Stimulus
 * binding on a submit button, and the wiring itself is what the Browser suite drives in real Chrome.
 */

function markup() {
    return `
        <form id="f" action="/planner/create" data-controller="planner-form">
            <select name="task" required>
                <option value="">Select a shift task</option>
                <option value="7">Briefing</option>
            </select>
            <button type="submit" data-action="planner-form#submit">Save</button>
        </form>`;
}

let application;
let controller;

async function start() {
    document.body.innerHTML = markup();
    application = Application.start();
    application.register('planner-form', PlannerFormController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('#f'),
        'planner-form',
    );
}

describe('planner form submission', () => {
    beforeEach(async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ ok: true }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        })));
        await start();
    });

    afterEach(() => {
        application?.stop();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('does not post while a required field is empty', async () => {
        await controller.submit(new Event('click'));

        expect(fetch).not.toHaveBeenCalled();
    });

    it('posts once the required field is filled', async () => {
        document.querySelector('select[name="task"]').value = '7';

        await controller.submit(new Event('click'));

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('leaves the submit button usable after a blocked submission', async () => {
        await controller.submit(new Event('click'));

        expect(document.querySelector('button[type="submit"]').disabled).toBe(false);
    });
});
