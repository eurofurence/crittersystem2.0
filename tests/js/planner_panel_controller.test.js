import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { Application } from '@hotwired/stimulus';
import PlannerPanelController from '../../assets/controllers/planner_panel_controller.js';

/*
 * The batch side of the planner panel.
 *
 * The grid identifies a block by the shift's public uuid, and the batch endpoints resolve ids[] the
 * same way. Coercing the id to a number here sent NaN for every selection, so batch edit and batch
 * delete silently did nothing - with correct markup, a 200 response and a green PHPUnit suite,
 * because the PHP tests posted integer primary keys the browser never sends.
 */

const UUID_A = '0d1f6f6e-1f4a-4d1a-9a63-2b2d6a4d21aa';
const UUID_B = '5c9a3b52-7f1e-4c9d-8b31-9f0f0c1e77bc';

function markup() {
    return `
        <div data-controller="planner-panel"
             data-planner-panel-panel-url-value="/panel/__ID__"
             data-action="planner:selection@window->planner-panel#update">
            <div data-planner-panel-target="overview">overview</div>
            <div data-planner-panel-target="single" hidden></div>
            <div data-planner-panel-target="batch" hidden>
                <span data-planner-panel-target="count">0</span>
                <form id="batch-edit"><span data-planner-panel-target="ids"></span></form>
                <form id="batch-delete"><span data-planner-panel-target="ids"></span></form>
            </div>
        </div>`;
}

let application;

async function start() {
    document.body.innerHTML = markup();
    application = Application.start();
    application.register('planner-panel', PlannerPanelController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function select(ids) {
    window.dispatchEvent(new CustomEvent('planner:selection', { detail: { ids } }));
}

function postedIds(formId) {
    return Array.from(document.querySelectorAll(`#${formId} input[name="ids[]"]`)).map((i) => i.value);
}

describe('planner panel batch selection', () => {
    beforeEach(async () => {
        await start();
    });

    afterEach(() => {
        application?.stop();
        document.body.innerHTML = '';
    });

    it('posts the shift uuids verbatim, not coerced to numbers', () => {
        select([UUID_A, UUID_B]);

        expect(postedIds('batch-edit')).toEqual([UUID_A, UUID_B]);
    });

    it('fills every ids container, so batch delete carries the selection too', () => {
        select([UUID_A, UUID_B]);

        expect(postedIds('batch-delete')).toEqual([UUID_A, UUID_B]);
    });

    it('shows the batch panel with the selected count', () => {
        select([UUID_A, UUID_B]);

        expect(document.querySelector('[data-planner-panel-target="batch"]').hidden).toBe(false);
        expect(document.querySelector('[data-planner-panel-target="count"]').textContent).toBe('2');
    });

    it('returns to the overview when the selection is cleared', () => {
        select([UUID_A, UUID_B]);
        select([]);

        expect(document.querySelector('[data-planner-panel-target="overview"]').hidden).toBe(false);
        expect(document.querySelector('[data-planner-panel-target="batch"]').hidden).toBe(true);
    });

    it('escapes an id before writing it into the hidden input', () => {
        // Two ids: a single selection opens the fetched edit form instead of the batch panel.
        select(['" onfocus="alert(1)', UUID_B]);

        const input = document.querySelector('#batch-edit input[name="ids[]"]');
        expect(input.getAttribute('onfocus')).toBeNull();
        expect(input.value).toBe('" onfocus="alert(1)');
    });
});
