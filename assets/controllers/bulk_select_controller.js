import { Controller } from '@hotwired/stimulus';

/*
 * Drives a checkbox column: a header box that selects every row, a live count, and a submit button
 * that stays disabled until something is ticked, so a bulk action can never post an empty
 * selection.
 *
 * The actions name `change` explicitly: Stimulus defaults an <input> to the `input` event, which a
 * checkbox does not reliably raise.
 *
 *   <form data-controller="bulk-select">
 *     <input type="checkbox" data-bulk-select-target="all" data-action="change->bulk-select#toggleAll">
 *     <input type="checkbox" name="items[]" data-bulk-select-target="item" data-action="change->bulk-select#refresh">
 *     <span data-bulk-select-target="count">0</span>
 *     <button data-bulk-select-target="submit">Give selected</button>
 *   </form>
 */
export default class extends Controller {
    static targets = ['all', 'item', 'count', 'submit'];

    connect() {
        this.refresh();
    }

    toggleAll() {
        const checked = this.allTarget.checked;
        this.itemTargets.forEach((item) => {
            if (!item.disabled) {
                item.checked = checked;
            }
        });
        this.refresh();
    }

    refresh() {
        const selectable = this.itemTargets.filter((item) => !item.disabled);
        const selected = selectable.filter((item) => item.checked);

        if (this.hasAllTarget) {
            this.allTarget.checked = selectable.length > 0 && selected.length === selectable.length;
            this.allTarget.indeterminate = selected.length > 0 && selected.length < selectable.length;
            this.allTarget.disabled = selectable.length === 0;
        }

        if (this.hasCountTarget) {
            this.countTarget.textContent = String(selected.length);
        }

        this.submitTargets.forEach((button) => {
            button.disabled = selected.length === 0;
        });
    }
}
