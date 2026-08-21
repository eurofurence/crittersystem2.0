import { describe, it, expect, afterEach } from 'vitest';
import { Application } from '@hotwired/stimulus';
import BulkSelectController from '../../assets/controllers/bulk_select_controller.js';

/*
 * The bulk hand-out and bulk revoke both post a list the server acts on, so the button must be
 * unreachable while that list is empty: an empty submit reloads the desk page for nothing and, on
 * the revoke form, looks to the operator like the revoke silently failed.
 */

function markup(rows = 2) {
    const items = Array.from({ length: rows }, (_, index) => `
        <input type="checkbox" name="items[]" value="item-${index}"
               data-bulk-select-target="item" data-action="change->bulk-select#refresh">`).join('');

    return `
        <div data-controller="bulk-select">
          <form>
            <input type="checkbox" data-bulk-select-target="all" data-action="change->bulk-select#toggleAll">
            ${items}
            <span data-bulk-select-target="count">0</span>
            <button data-bulk-select-target="submit">Give selected</button>
          </form>
        </div>`;
}

let application;

async function start(html = markup()) {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('bulk-select', BulkSelectController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const all = () => document.querySelector('[data-bulk-select-target="all"]');
const items = () => Array.from(document.querySelectorAll('[data-bulk-select-target="item"]'));
const submit = () => document.querySelector('[data-bulk-select-target="submit"]');
const count = () => document.querySelector('[data-bulk-select-target="count"]').textContent;

/* happy-dom's synthetic click does not reach a Stimulus binding, so the state change is stated. */
function tick(box, checked) {
    box.checked = checked;
    box.dispatchEvent(new Event('change'));
}

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
});

describe('bulk select', () => {
    it('keeps the submit button disabled until something is selected', async () => {
        await start();
        expect(submit().disabled).toBe(true);

        tick(items()[0], true);
        expect(submit().disabled).toBe(false);
        expect(count()).toBe('1');

        tick(items()[0], false);
        expect(submit().disabled).toBe(true);
        expect(count()).toBe('0');
    });

    it('selects and clears every row from the header box', async () => {
        await start();

        tick(all(), true);
        expect(items().every((item) => item.checked)).toBe(true);
        expect(count()).toBe('2');

        tick(all(), false);
        expect(items().some((item) => item.checked)).toBe(false);
        expect(count()).toBe('0');
    });

    it('shows a partial selection as indeterminate rather than as select-all', async () => {
        await start();

        tick(items()[0], true);
        expect(all().checked).toBe(false);
        expect(all().indeterminate).toBe(true);

        tick(items()[1], true);
        expect(all().checked).toBe(true);
        expect(all().indeterminate).toBe(false);
    });

    it('disables the header box when there is nothing to select', async () => {
        await start(markup(0));

        expect(all().disabled).toBe(true);
        expect(submit().disabled).toBe(true);
    });
});
