import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import PlannerController from '../../assets/controllers/planner_controller.js';

/*
 * Another manager's edits arriving in this manager's planner.
 *
 * Applying one is not a matter of calling reloadGrid(): that replaces the grid wholesale and clears
 * the selection, so doing it at the wrong moment pulls the blocks out from under a drag, empties the
 * side panel someone was acting through, or refreshes the page behind an open modal. Whether the
 * change is held back is invisible to every other test layer - the markup and the response are
 * identical either way - so it is pinned here.
 */

const BLOCK = (uuid) => `
    <div class="planner-block"
         data-planner-target="block"
         data-shift-id="${uuid}"
         data-start="2026-06-01T10:00:00+00:00"
         data-move-url="/move/${uuid}"
         data-delete-url="/delete/${uuid}"
         style="top: 10%; height: 5%; left: 0%; width: calc(100% - 4px);"></div>`;

function markup() {
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
                    ${BLOCK('shift-a')}
                </div>
            </div>
        </div>`;
}

let application;
let controller;

async function mount() {
    document.body.innerHTML = markup();
    application = Application.start();
    application.register('planner', PlannerController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('.planner'),
        'planner',
    );
}

function remoteChange() {
    window.dispatchEvent(new CustomEvent('planner:remote-changed'));
}

beforeEach(() => {
    // reloadGrid() fetches the current page; the response body is irrelevant here, only whether it
    // is requested at all.
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html><body></body></html>', { status: 200 })));
});

afterEach(async () => {
    document.body.innerHTML = '';
    await new Promise((resolve) => setTimeout(resolve, 0));
    application?.stop();
    vi.unstubAllGlobals();
});

describe('remote planner changes', () => {
    it('applies immediately when the manager is idle', async () => {
        await mount();

        remoteChange();

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
    });

    it('holds back while a gesture is in progress, and applies when it ends', async () => {
        await mount();
        controller.gesture = { kind: 'paint' };

        remoteChange();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(fetch).not.toHaveBeenCalled();

        // The gesture finishes; handlePointerUp clears it and releases what was held back.
        controller.handlePointerUp();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
    });

    it('holds back while blocks are selected', async () => {
        await mount();
        controller.selected.add(document.querySelector('.planner-block'));

        remoteChange();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(fetch).not.toHaveBeenCalled();
    });

    it('holds back while a modal is open, and applies when it closes', async () => {
        await mount();
        document.body.insertAdjacentHTML('beforeend', '<div class="modal show"></div>');

        remoteChange();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(fetch).not.toHaveBeenCalled();

        document.querySelector('.modal.show').remove();
        document.dispatchEvent(new Event('hidden.bs.modal'));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
    });

    /* Several arriving while busy must collapse into one refresh, not queue up N of them. */
    it('applies one refresh however many changes arrived while busy', async () => {
        await mount();
        controller.gesture = { kind: 'paint' };

        remoteChange();
        remoteChange();
        remoteChange();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(fetch).not.toHaveBeenCalled();

        controller.handlePointerUp();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    });

    /* The manager's own edits are not deferred - they asked for them. */
    it('does not defer the manager’s own changes', async () => {
        await mount();
        controller.selected.add(document.querySelector('.planner-block'));

        window.dispatchEvent(new CustomEvent('planner:changed'));

        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
    });
});
