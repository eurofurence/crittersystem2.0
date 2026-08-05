import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';
import { confirmModal } from '../js/modal.js';

/*
 * Inline "new shift group" beside the planner's batch group picker.
 *
 * Creates the group AND puts the current selection in it in one step: naming a group for the shifts
 * you have selected is a single intent, and a create-only button leaves an empty group behind
 * whenever somebody forgets to press Apply. The other batch fields stay independent and still need
 * Apply.
 *
 * The surrounding panel is itself a form, so this posts on its own rather than nesting a second one
 * (see shift_task_create_controller, which solves the same problem for shift tasks).
 */
export default class extends Controller {
    static targets = ['select', 'panel', 'name', 'error'];
    static values = { url: String, department: String, token: String, blankMessage: String, failedMessage: String };

    open() {
        this.panelTarget.hidden = false;
        this.clearError();
        this.nameTarget.focus();
    }

    close() {
        this.panelTarget.hidden = true;
        this.nameTarget.value = '';
        this.clearError();
    }

    // Enter inside the name field must create the group, not submit the surrounding batch form.
    keydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.create();
        }
    }

    async create() {
        const name = this.nameTarget.value.trim();
        if (name === '') {
            this.showError(this.blankMessageValue);
            return;
        }

        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('department', this.departmentValue);
        body.append('name', name);
        // The selection lives as hidden inputs the panel controller writes into this form; reading
        // them here keeps the button in step with whatever is selected right now.
        this.selectedIds().forEach((id) => body.append('ids[]', id));

        try {
            let data = await this.post(body);
            if (data === null) {
                return;
            }

            // Some of the selected shifts already have volunteers on them, so this would leave people
            // on part of a commitment. Same question the management screen asks, asked before
            // anything is written.
            if (data.confirm) {
                if (!(await confirmModal(data.confirm))) {
                    return;
                }
                body.append('confirm', '1');
                data = await this.post(body);
                if (data === null) {
                    return;
                }
            }

            if (data.ok === false) {
                this.showError(data.error || this.failedMessageValue);
                return;
            }

            this.addOption(data.id, data.name);
            this.close();
            // The shifts changed, so the grid has to catch up.
            window.dispatchEvent(new CustomEvent('planner:changed'));
        } catch (e) {
            console.error('Creating the shift group failed.', e);
            this.showError(this.failedMessageValue);
        }
    }

    /** Returns the parsed body, or null when the session handler already took over the response. */
    async post(body) {
        const response = await backgroundFetch(this.urlValue, { method: 'POST', body });
        if (response === null) {
            return null;
        }

        return await response.json().catch(() => ({ ok: false }));
    }

    /** @return {string[]} the shift uuids currently selected in the grid */
    selectedIds() {
        const form = this.element.closest('form');
        if (form === null) {
            return [];
        }

        return Array.from(form.querySelectorAll('input[name="ids[]"]')).map((input) => input.value);
    }

    addOption(id, name) {
        const option = document.createElement('option');
        option.value = String(id);
        option.textContent = name;
        this.selectTarget.appendChild(option);
        // Set explicitly rather than relying on the Option constructor's selected flag: the shifts
        // are already in this group, so the picker has to show that, and a picker left on "leave
        // unchanged" would read as though nothing had happened.
        this.selectTarget.value = String(id);
        this.selectTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    showError(message) {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = message || '';
            this.errorTarget.hidden = !message;
        }
    }

    clearError() {
        this.showError('');
    }
}
