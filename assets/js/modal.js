/*
 * Programmatic confirmation / notice dialogs rendered as Bootstrap (Tabler)
 * modals. Use these - never window.confirm() / window.alert(), which cannot be
 * styled, are not theme-aware, and block the main thread.
 *
 *   import { confirmModal, alertModal } from '../js/modal.js';
 *   if (await confirmModal('Delete this?')) { ... }
 *   await alertModal('Saved with warnings:\n' + list.join('\n'));
 *
 * Both return a Promise. confirmModal resolves true/false; alertModal resolves
 * when dismissed. The modal DOM is created on demand and removed on close.
 */

function buildModal({ title, message, confirmLabel, cancelLabel, variant, showCancel }) {
    const el = document.createElement('div');
    el.className = 'modal fade';
    el.tabIndex = -1;
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML =
        '<div class="modal-dialog modal-dialog-centered">' +
        '<div class="modal-content">' +
        '<div class="modal-header"><h5 class="modal-title"></h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
        '<div class="modal-body" style="white-space:pre-line"></div>' +
        '<div class="modal-footer">' +
        (showCancel ? '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-role="cancel"></button>' : '') +
        '<button type="button" class="btn" data-role="confirm"></button>' +
        '</div></div></div>';

    // textContent so a message can never inject markup.
    el.querySelector('.modal-title').textContent = title;
    el.querySelector('.modal-body').textContent = message;
    const confirmBtn = el.querySelector('[data-role="confirm"]');
    confirmBtn.classList.add('btn-' + (variant || 'primary'));
    confirmBtn.textContent = confirmLabel;
    if (showCancel) {
        el.querySelector('[data-role="cancel"]').textContent = cancelLabel;
    }
    return el;
}

function openModal(opts) {
    // Sanctioned fallback (the one allowed exception): if the Bootstrap JS is not
    // available a modal genuinely cannot be shown, so degrade to the native
    // dialog rather than silently skipping the confirmation.
    if (!window.bootstrap || !window.bootstrap.Modal) {
        if (opts.showCancel) {
            return Promise.resolve(window.confirm(opts.message));
        }
        window.alert(opts.message);
        return Promise.resolve(true);
    }

    return new Promise((resolve) => {
        const el = buildModal(opts);
        document.body.appendChild(el);
        const modal = window.bootstrap.Modal.getOrCreateInstance(el);
        let result = false;
        el.querySelector('[data-role="confirm"]').addEventListener('click', () => {
            result = true;
            modal.hide();
        });
        el.addEventListener('hidden.bs.modal', () => {
            el.remove();
            resolve(result);
        });
        modal.show();
    });
}

export function confirmModal(message, options = {}) {
    return openModal({
        title: options.title || 'Please confirm',
        message,
        confirmLabel: options.confirmLabel || 'Confirm',
        cancelLabel: options.cancelLabel || 'Cancel',
        variant: options.variant || 'danger',
        showCancel: true,
    });
}

export function alertModal(message, options = {}) {
    return openModal({
        title: options.title || 'Notice',
        message,
        confirmLabel: options.confirmLabel || 'OK',
        cancelLabel: 'Cancel',
        variant: options.variant || 'primary',
        showCancel: false,
    }).then(() => undefined);
}
