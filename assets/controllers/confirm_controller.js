import { Controller } from '@hotwired/stimulus';
import { confirmModal } from '../js/modal.js';

/*
 * Replaces native window.confirm() on form submissions with a styled Bootstrap
 * modal. Put it on the <form> whose submit needs confirming:
 *
 *   <form method="post" action="..."
 *         data-controller="confirm"
 *         data-confirm-message-value="Delete this item?"
 *         data-confirm-title-value="Delete item"
 *         data-confirm-variant-value="danger">
 *
 * The first submit is intercepted and the modal shown; the form is only
 * submitted (preserving the clicked submit button / Turbo) once confirmed.
 */
export default class extends Controller {
    static values = {
        message: String,
        title: { type: String, default: 'Please confirm' },
        confirmLabel: { type: String, default: 'Confirm' },
        cancelLabel: { type: String, default: 'Cancel' },
        variant: { type: String, default: 'danger' },
    };

    connect() {
        this.confirmed = false;
        this.onSubmit = this.onSubmit.bind(this);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }

    onSubmit(event) {
        // A confirmed re-submit passes straight through.
        if (this.confirmed) {
            this.confirmed = false;
            return;
        }

        event.preventDefault();
        const submitter = event.submitter;

        confirmModal(this.messageValue, {
            title: this.titleValue,
            confirmLabel: this.confirmLabelValue,
            cancelLabel: this.cancelLabelValue,
            variant: this.variantValue,
        }).then((ok) => {
            if (!ok) {
                return;
            }
            this.confirmed = true;
            if (typeof this.element.requestSubmit === 'function') {
                this.element.requestSubmit(submitter || undefined);
            } else {
                this.element.submit();
            }
        });
    }
}
