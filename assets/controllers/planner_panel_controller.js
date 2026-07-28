import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';

/*
 * The ids come from the grid's own markup rather than from a user, but they are interpolated into an
 * attribute, so they are escaped anyway: an unescaped value would let any future source of block ids
 * close the attribute and inject markup into the panel.
 */
function escapeAttribute(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

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
        /*
         * The grid identifies a block by the shift's public uuid, and the server resolves ids[] the
         * same way. Coercing to a number here silently sent NaN and every batch action became a
         * no-op, which nothing on screen reported.
         */
        this.idsTargets.forEach((container) => {
            container.innerHTML = ids
                .map((id) => `<input type="hidden" name="ids[]" value="${escapeAttribute(id)}">`)
                .join('');
        });
    }
}
