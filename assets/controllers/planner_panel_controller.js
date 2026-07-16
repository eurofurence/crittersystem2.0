import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';

/*
 * Selection-driven planner side panel. Listens for the planner's
 * selection event and swaps between three states:
 *   - none:  the planning overview;
 *   - one:   the single-shift edit form, fetched from the server;
 *   - many:  the batch-edit form (only compatible shared fields).
 */
export default class extends Controller {
    static targets = ['overview', 'single', 'batch', 'count', 'ids'];
    static values = { panelUrl: String };

    update(event) {
        const ids = event.detail.ids || [];
        this.hideAll();

        if (ids.length === 0) {
            this.overviewTarget.hidden = false;
        } else if (ids.length === 1) {
            this.showSingle(ids[0]);
        } else {
            this.showBatch(ids);
        }
    }

    hideAll() {
        this.overviewTarget.hidden = true;
        this.singleTarget.hidden = true;
        this.batchTarget.hidden = true;
    }

    async showSingle(id) {
        this.singleTarget.hidden = false;
        this.singleTarget.innerHTML = '<div class="text-secondary p-3">Loading…</div>';
        try {
            const response = await backgroundFetch(this.panelUrlValue.replace('__ID__', id));
            if (response === null) {
                return;
            }
            this.singleTarget.innerHTML = await response.text();
        } catch (e) {
            console.error('Could not load the shift panel.', e);
            this.singleTarget.innerHTML = '<div class="text-danger p-3">Could not load the shift.</div>';
        }
    }

    showBatch(ids) {
        this.batchTarget.hidden = false;
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(ids.length);
        }
        if (this.hasIdsTarget) {
            this.idsTarget.innerHTML = ids
                .map((id) => `<input type="hidden" name="ids[]" value="${Number(id)}">`)
                .join('');
        }
    }
}
