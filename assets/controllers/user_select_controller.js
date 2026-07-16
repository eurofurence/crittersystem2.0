import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';

/*
 * Reusable tag-style user picker. Type a username, pick from a live dropdown, and the user is added
 * as a removable chip in the same field; each chip carries a hidden `<name>[]` input so a plain form
 * POST submits every picked id. Search is username-only partial matching, served as JSON by the URL
 * given in the `url` value (see App\Controller\AssignmentController::search for the shape).
 *
 *   data-controller="user-select"
 *   data-user-select-url-value="/…/search"
 *   data-user-select-name-value="users"        // hidden inputs are named users[]
 *   data-user-select-min-chars-value="1"
 *   data-user-select-staff-suffix-value="true" // append " (staff)" to staff usernames
 */
export default class extends Controller {
    static targets = ['control', 'input', 'menu', 'chips', 'hidden'];
    static values = {
        url: String,
        name: String,
        minChars: { type: Number, default: 1 },
        staffSuffix: { type: Boolean, default: true },
    };

    connect() {
        this.selected = new Set();
        // Adopt any server-rendered pre-selection: register the id and make its chip removable.
        this.chipsTarget.querySelectorAll('[data-user-select-id]').forEach((chip) => {
            const id = chip.dataset.userSelectId;
            this.selected.add(id);
            const hidden = this.hiddenTarget.querySelector(`input[value="${CSS.escape(id)}"]`);
            this.attachRemoval(chip, id, hidden);
        });
        this.results = [];
        this.activeIndex = -1;
        this.debounceTimer = null;
        this.abortController = null;
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.closeMenu();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        clearTimeout(this.debounceTimer);
        this.abortController?.abort();
        document.removeEventListener('click', this.onDocumentClick);
    }

    focusInput() {
        this.inputTarget.focus();
    }

    onInput() {
        clearTimeout(this.debounceTimer);
        const q = this.inputTarget.value.trim();
        if (q.length < this.minCharsValue) {
            this.closeMenu();
            return;
        }
        this.debounceTimer = setTimeout(() => this.fetchResults(q), 200);
    }

    async fetchResults(q) {
        this.abortController?.abort();
        this.abortController = new AbortController();
        try {
            const url = `${this.urlValue}?q=${encodeURIComponent(q)}`;
            const response = await backgroundFetch(url, { signal: this.abortController.signal });
            if (response === null || !response.ok) {
                return;
            }
            const data = await response.json();
            // Never offer someone who is already picked.
            this.results = (data.results || []).filter((r) => !this.selected.has(String(r.id)));
            this.activeIndex = this.results.length > 0 ? 0 : -1;
            this.renderMenu();
        } catch (e) {
            if (e.name !== 'AbortError') {
                console.error('User search failed.', e);
            }
        }
    }

    onKeydown(event) {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                this.move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.move(-1);
                break;
            case 'Enter':
                if (this.menuOpen && this.results[this.activeIndex]) {
                    event.preventDefault();
                    this.pick(this.results[this.activeIndex]);
                }
                break;
            case 'Escape':
                this.closeMenu();
                break;
            case 'Backspace':
                if (this.inputTarget.value === '') {
                    this.removeLastChip();
                }
                break;
        }
    }

    move(delta) {
        if (this.results.length === 0) {
            return;
        }
        this.activeIndex = (this.activeIndex + delta + this.results.length) % this.results.length;
        this.renderMenu();
    }

    pick(item) {
        const id = String(item.id);
        if (this.selected.has(id)) {
            return;
        }
        this.selected.add(id);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = `${this.nameValue}[]`;
        hidden.value = id;
        this.hiddenTarget.appendChild(hidden);

        this.chipsTarget.appendChild(this.buildChip(item, hidden));

        this.inputTarget.value = '';
        this.results = [];
        this.closeMenu();
        this.inputTarget.focus();
    }

    buildChip(item, hidden) {
        const id = String(item.id);
        const chip = document.createElement('span');
        chip.className = 'user-select-chip badge d-inline-flex align-items-center gap-1';
        chip.dataset.userSelectId = id;
        chip.appendChild(this.avatarEl(item, 'avatar-xs'));

        const label = document.createElement('span');
        label.textContent = this.displayName(item);
        chip.appendChild(label);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn-close';
        remove.setAttribute('aria-label', `Remove ${item.name}`);
        chip.appendChild(remove);

        this.attachRemoval(chip, id, hidden);

        return chip;
    }

    attachRemoval(chip, id, hidden) {
        chip.querySelector('.btn-close')?.addEventListener('click', () => {
            this.selected.delete(id);
            hidden?.remove();
            chip.remove();
            this.inputTarget.focus();
        });
    }

    removeLastChip() {
        const chips = this.chipsTarget.querySelectorAll('.user-select-chip .btn-close');
        if (chips.length > 0) {
            chips[chips.length - 1].click();
        }
    }

    renderMenu() {
        this.menuTarget.replaceChildren();
        if (this.results.length === 0) {
            this.closeMenu();
            return;
        }
        this.results.forEach((item, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'dropdown-item d-flex align-items-center gap-2';
            if (index === this.activeIndex) {
                option.classList.add('active');
            }
            option.appendChild(this.avatarEl(item, 'avatar-xs'));
            const label = document.createElement('span');
            label.textContent = this.displayName(item);
            option.appendChild(label);
            // mousedown, not click: fire before the input's blur would close the menu.
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.pick(item);
            });
            this.menuTarget.appendChild(option);
        });
        this.openMenu();
    }

    avatarEl(item, sizeClass) {
        const el = document.createElement('span');
        el.className = `avatar ${sizeClass}`;
        if (item.avatar) {
            el.style.backgroundImage = `url('${item.avatar}')`;
        } else {
            el.textContent = item.name.slice(0, 2).toUpperCase();
        }
        return el;
    }

    displayName(item) {
        return this.staffSuffixValue && item.staff ? `${item.name} (staff)` : item.name;
    }

    openMenu() {
        this.menuTarget.classList.add('show');
        this.menuOpen = true;
    }

    closeMenu() {
        this.menuTarget.classList.remove('show');
        this.menuOpen = false;
        this.activeIndex = -1;
    }
}
