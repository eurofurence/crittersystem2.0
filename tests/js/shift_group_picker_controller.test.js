import { describe, it, expect, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ShiftGroupPickerController from '../../assets/controllers/shift_group_picker_controller.js';

/*
 * The shift-group member picker.
 *
 * The behaviour worth protecting is the selection surviving a filter change: the whole point of
 * filtering a department's few hundred shifts is to work across several views, tick two on Monday,
 * filter to Tuesday, tick a third, and add all three. The selection therefore lives in the controller
 * rather than in the DOM, which is exactly the kind of state that rots silently, so these tests pin
 * down that ticks are restored after a re-render, that out-of-view ticks are still submitted, and
 * that the manager is told how many of them there are.
 */

function listHtml(shifts) {
    return `<ul>${shifts.map((s) => `
        <li><label><input type="checkbox" value="${s}"></label></li>`).join('')}</ul>`;
}

function markup() {
    return `
        <div id="picker"
             data-controller="shift-group-picker"
             data-shift-group-picker-candidates-url-value="/manage/shift-groups/g/candidates"
             data-shift-group-picker-members-url-value="/manage/shift-groups/g/members-list"
             data-shift-group-picker-add-url-value="/manage/shift-groups/g/members"
             data-shift-group-picker-token-value="tok"
             data-shift-group-picker-add-label-value="Add __COUNT__ selected shift(s)"
             data-shift-group-picker-offscreen-label-value="__COUNT__ more selected that the current filter does not show."
             data-shift-group-picker-failed-message-value="It failed."
             data-shift-group-picker-debounce-value="0">
            <div data-shift-group-picker-target="members"></div>
            <select data-shift-group-picker-target="day"><option value="" selected></option><option value="2036-06-02">Tue</option></select>
            <select data-shift-group-picker-target="audience"><option value="" selected></option></select>
            <select data-shift-group-picker-target="type"><option value="" selected></option></select>
            <input data-shift-group-picker-target="query" value="">
            <input type="checkbox" data-shift-group-picker-target="past">
            <div data-shift-group-picker-target="candidates">${listHtml(['a', 'b'])}</div>
            <button data-shift-group-picker-target="submit" disabled></button>
            <button data-shift-group-picker-target="clear" hidden></button>
            <span data-shift-group-picker-target="offscreen" hidden></span>
            <div data-shift-group-picker-target="error" hidden></div>
        </div>`;
}

let application;
let controller;

async function start(fetchImpl) {
    document.body.innerHTML = markup();
    vi.stubGlobal('fetch', fetchImpl ?? vi.fn(async () => new Response(listHtml(['a', 'b']), { status: 200 })));

    application = Application.start();
    application.register('shift-group-picker', ShiftGroupPickerController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('#picker'),
        'shift-group-picker',
    );
}

function tick(value) {
    const box = document.querySelector(`input[type="checkbox"][value="${value}"]`);
    box.checked = true;
    box.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('shift group member picker', () => {
    afterEach(() => {
        application?.stop();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('counts the selection on the button and disables it when empty', async () => {
        await start();

        expect(controller.submitTarget.disabled).toBe(true);

        tick('a');
        expect(controller.submitTarget.textContent).toContain('Add 1 selected');
        expect(controller.submitTarget.disabled).toBe(false);
        expect(controller.clearTarget.hidden).toBe(false);
    });

    it('keeps the selection when the filter replaces the list', async () => {
        // The refreshed list holds a different shift, so 'a' is selected but no longer on screen.
        await start(vi.fn(async () => new Response(listHtml(['c']), { status: 200 })));
        tick('a');

        await controller.load();

        expect(controller.selected.has('a')).toBe(true);
        expect(controller.submitTarget.textContent).toContain('Add 1 selected');
        expect(controller.offscreenTarget.hidden).toBe(false);
        expect(controller.offscreenTarget.textContent).toContain('1 more selected');
    });

    it('re-ticks boxes that come back in a later view', async () => {
        await start(vi.fn(async () => new Response(listHtml(['a', 'z']), { status: 200 })));
        tick('a');

        await controller.load();

        expect(document.querySelector('input[value="a"]').checked).toBe(true);
        expect(document.querySelector('input[value="z"]').checked).toBe(false);
        expect(controller.offscreenTarget.hidden).toBe(true);
    });

    it('submits shifts the current filter is not showing', async () => {
        const fetchMock = vi.fn(async (url, options) => {
            if (options && options.method === 'POST') {
                return new Response(JSON.stringify({ ok: true, added: 2 }), { status: 200 });
            }
            return new Response(listHtml(['c']), { status: 200 });
        });
        await start(fetchMock);
        tick('a');
        tick('b');
        await controller.load(); // both now out of view

        await controller.add();

        const post = fetchMock.mock.calls.find((c) => c[1] && c[1].method === 'POST');
        expect(post[1].body.getAll('shifts[]').sort()).toEqual(['a', 'b']);
    });

    it('asks before leaving volunteers on part of the group, then replays with the flag', async () => {
        let posts = 0;
        const fetchMock = vi.fn(async (url, options) => {
            if (options && options.method === 'POST') {
                posts += 1;
                return posts === 1
                    ? new Response(JSON.stringify({ ok: false, confirm: 'Continue?' }), { status: 200 })
                    : new Response(JSON.stringify({ ok: true, added: 1 }), { status: 200 });
            }
            return new Response(listHtml(['a']), { status: 200 });
        });
        await start(fetchMock);
        vi.stubGlobal('confirm', vi.fn(() => true));
        tick('a');

        await controller.add();

        const second = fetchMock.mock.calls.filter((c) => c[1] && c[1].method === 'POST')[1];
        expect(second[1].body.get('confirm')).toBe('1');
        expect(controller.selected.size).toBe(0);
    });

    it('writes nothing when the question is declined', async () => {
        const fetchMock = vi.fn(async (url, options) => {
            if (options && options.method === 'POST') {
                return new Response(JSON.stringify({ ok: false, confirm: 'Continue?' }), { status: 200 });
            }
            return new Response(listHtml(['a']), { status: 200 });
        });
        await start(fetchMock);
        vi.stubGlobal('confirm', vi.fn(() => false));
        tick('a');

        await controller.add();

        expect(fetchMock.mock.calls.filter((c) => c[1] && c[1].method === 'POST')).toHaveLength(1);
        expect(controller.selected.has('a')).toBe(true);
    });

    it('shows the server error and keeps the selection', async () => {
        const fetchMock = vi.fn(async (url, options) => {
            if (options && options.method === 'POST') {
                return new Response(JSON.stringify({ ok: false, error: 'Already in the group "Owner".' }), { status: 200 });
            }
            return new Response(listHtml(['a']), { status: 200 });
        });
        await start(fetchMock);
        tick('a');

        await controller.add();

        expect(controller.errorTarget.hidden).toBe(false);
        expect(controller.errorTarget.textContent).toContain('Already in the group');
        expect(controller.selected.has('a')).toBe(true);
    });

    it('clear empties the selection and unticks what is on screen', async () => {
        await start();
        tick('a');
        tick('b');

        controller.clear();

        expect(controller.selected.size).toBe(0);
        expect(document.querySelector('input[value="a"]').checked).toBe(false);
        expect(controller.submitTarget.disabled).toBe(true);
        expect(controller.clearTarget.hidden).toBe(true);
    });

    it('sends every filter to the server', async () => {
        const fetchMock = vi.fn(async () => new Response(listHtml([]), { status: 200 }));
        await start(fetchMock);
        controller.dayTarget.value = '2036-06-02';
        controller.queryTarget.value = '  rehearsal  ';
        controller.pastTarget.checked = true;

        await controller.load();

        const url = new URL(String(fetchMock.mock.calls.at(-1)[0]), 'http://localhost');
        expect(url.searchParams.get('day')).toBe('2036-06-02');
        expect(url.searchParams.get('q')).toBe('rehearsal');
        expect(url.searchParams.get('past')).toBe('1');
    });
});
