import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';

/*
 * Inline "new shift task" next to a shift-task picker.
 *
 * The Add Shift modal and the wizard are themselves forms, and a shift task is now required to save
 * either of them, so sending the manager to the management screen to create one would throw the
 * half-filled form away. A nested <form> is invalid HTML and a nested Bootstrap modal fights the
 * outer one, so this posts the new task on its own and grafts the result into the picker.
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

    // Enter inside the name field must create the task, not submit the surrounding form.
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

        try {
            const response = await backgroundFetch(this.urlValue, { method: 'POST', body });
            if (response === null) {
                return;
            }
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) {
                this.showError(data.error || this.failedMessageValue);
                return;
            }
            this.addOption(data.id, data.name);
            this.close();
        } catch (e) {
            console.error('Creating the shift task failed.', e);
            this.showError(this.failedMessageValue);
        }
    }

    addOption(id, name) {
        // TODO: verify in a real browser that the constructor's `selected` flag actually selects this
        // option; under happy-dom it does not, so the picker silently keeps its previous value. If it
        // reproduces in Chrome, build the option with createElement and set select.value explicitly,
        // as shift_group_create_controller does. See docs/tasks/todo-option-constructor-selection.md.
        const option = new Option(name, String(id), true, true);
        this.selectTarget.add(option);
        // A picker that was empty (and therefore disabled the form) is usable from here on.
        this.selectTarget.disabled = false;
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
