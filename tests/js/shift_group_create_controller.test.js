import { describe, it, expect, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ShiftGroupCreateController from '../../assets/controllers/shift_group_create_controller.js';

/*
 * The planner's "+ new shift group" button. It creates the group AND puts the current selection in
 * it, so the things worth protecting are: it sends the ids the grid currently has selected, Enter
 * does not submit the surrounding batch form, and a server-side question about volunteers left on a
 * partial commitment is answered before anything is written.
 *
 * Actions are invoked directly: happy-dom does not deliver a synthetic click to a Stimulus binding,
 * and the wiring itself is what the Browser suite drives in real Chrome.
 */

function markup() {
    return `
        <form id="batch" method="post" action="/manage-shifts/planner/batch">
            <span data-ids>
                <input type="hidden" name="ids[]" value="uuid-a">
                <input type="hidden" name="ids[]" value="uuid-b">
            </span>
            <div id="picker"
                 data-controller="shift-group-create"
                 data-shift-group-create-url-value="/manage-shifts/planner/shift-group"
                 data-shift-group-create-department-value="dept-uuid"
                 data-shift-group-create-token-value="tok"
                 data-shift-group-create-blank-message-value="A shift group needs a name."
                 data-shift-group-create-failed-message-value="It failed.">
                <select data-shift-group-create-target="select">
                    <option value="">Leave unchanged</option>
                </select>
                <div data-shift-group-create-target="panel" hidden>
                    <input type="text" data-shift-group-create-target="name">
                    <div data-shift-group-create-target="error" hidden></div>
                </div>
            </div>
        </form>`;
}

let application;
let controller;

async function start(responses) {
    document.body.innerHTML = markup();
    const queue = [...responses];
    vi.stubGlobal('fetch', vi.fn(async () => {
        const body = queue.length > 1 ? queue.shift() : queue[0];
        return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }));

    application = Application.start();
    application.register('shift-group-create', ShiftGroupCreateController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('#picker'),
        'shift-group-create',
    );
}

function sentBody(call = 0) {
    return fetch.mock.calls[call][1].body;
}

describe('planner new shift group', () => {
    afterEach(() => {
        application?.stop();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('refuses a blank name without calling the server', async () => {
        await start([{ ok: true, id: 1, name: 'x' }]);
        controller.nameTarget.value = '   ';

        await controller.create();

        expect(fetch).not.toHaveBeenCalled();
        expect(controller.errorTarget.hidden).toBe(false);
    });

    it('sends the shifts currently selected in the grid', async () => {
        await start([{ ok: true, id: 7, name: 'Main Show', assigned: 2 }]);
        controller.nameTarget.value = 'Main Show';

        await controller.create();

        const body = sentBody();
        expect(body.getAll('ids[]')).toEqual(['uuid-a', 'uuid-b']);
        expect(body.get('name')).toBe('Main Show');
        expect(body.get('department')).toBe('dept-uuid');
    });

    it('adds the new group to the picker and selects it', async () => {
        await start([{ ok: true, id: 7, name: 'Main Show', assigned: 2 }]);
        controller.nameTarget.value = 'Main Show';

        await controller.create();

        expect(controller.selectTarget.value).toBe('7');
        expect(controller.panelTarget.hidden).toBe(true);
    });

    it('asks before creating when volunteers would be left on part of the group', async () => {
        await start([
            { ok: false, confirm: '2 volunteer(s) would end up on only part of this group. Continue?' },
            { ok: true, id: 7, name: 'Main Show', assigned: 2 },
        ]);
        vi.stubGlobal('confirm', vi.fn(() => true));
        controller.nameTarget.value = 'Main Show';

        await controller.create();

        // Answered yes, so the identical request is replayed with the flag set.
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(sentBody(1).get('confirm')).toBe('1');
        expect(controller.selectTarget.value).toBe('7');
    });

    it('creates nothing when the question is declined', async () => {
        await start([{ ok: false, confirm: 'Continue?' }]);
        vi.stubGlobal('confirm', vi.fn(() => false));
        controller.nameTarget.value = 'Main Show';

        await controller.create();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(controller.selectTarget.value).toBe('');
    });

    it('shows the server error rather than pretending it worked', async () => {
        await start([{ ok: false, error: 'A shift group called "Main Show" already exists in this department.' }]);
        controller.nameTarget.value = 'Main Show';

        await controller.create();

        expect(controller.errorTarget.hidden).toBe(false);
        expect(controller.errorTarget.textContent).toContain('already exists');
        expect(controller.selectTarget.value).toBe('');
    });

    it('does not submit the surrounding batch form when Enter is pressed', async () => {
        await start([{ ok: true, id: 7, name: 'Main Show', assigned: 2 }]);
        controller.nameTarget.value = 'Main Show';
        let submitted = false;
        document.querySelector('#batch').addEventListener('submit', () => { submitted = true; });

        const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true, bubbles: true });
        controller.keydown(event);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(event.defaultPrevented).toBe(true);
        expect(submitted).toBe(false);
    });
});
