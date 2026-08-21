import { Controller } from '@hotwired/stimulus';

/*
 * Puts the operator back where they were after a form post.
 *
 * Every hand-out, revoke, correction and check-in on the info desk page answers with a redirect
 * back to the same long page, which otherwise reopens at the top with the first tab showing and
 * makes the operator find their place again for each item. Before a submit leaves the page this
 * stores the window scroll, the scroll inside each scrollable panel and the open tab; the next
 * render puts all three back.
 *
 * The stash carries a timestamp and is dropped once used or once it is older than the ttl, so
 * arriving at the page by navigation still starts at the top rather than somewhere a previous
 * visit left off.
 */
export default class extends Controller {
    static values = {
        key: String,
        ttl: { type: Number, default: 20000 },
    };

    connect() {
        this.onSubmit = this.save.bind(this);
        this.onTurboLoad = this.restore.bind(this);
        document.addEventListener('submit', this.onSubmit, true);
        document.addEventListener('turbo:load', this.onTurboLoad);
        this.frame = requestAnimationFrame(() => this.restore());
    }

    disconnect() {
        document.removeEventListener('submit', this.onSubmit, true);
        document.removeEventListener('turbo:load', this.onTurboLoad);
        cancelAnimationFrame(this.frame);
    }

    save() {
        const state = {
            at: Date.now(),
            y: window.scrollY,
            panels: this.panels().map((panel) => panel.scrollTop),
            tab: this.activeTab()?.getAttribute('href') ?? null,
        };

        try {
            window.sessionStorage.setItem(this.storageKey(), JSON.stringify(state));
        } catch {
            // A browser refusing session storage costs the scroll position, nothing else.
        }
    }

    restore() {
        const state = this.take();
        if (!state) {
            return;
        }

        if (state.tab) {
            this.showTab(state.tab);
        }

        this.panels().forEach((panel, index) => {
            const top = state.panels?.[index];
            if (typeof top === 'number') {
                panel.scrollTop = top;
            }
        });

        if (typeof state.y === 'number') {
            window.scrollTo(0, state.y);
        }
    }

    take() {
        let raw = null;
        try {
            raw = window.sessionStorage.getItem(this.storageKey());
            window.sessionStorage.removeItem(this.storageKey());
        } catch {
            return null;
        }

        if (!raw) {
            return null;
        }

        try {
            const state = JSON.parse(raw);
            return Date.now() - state.at > this.ttlValue ? null : state;
        } catch {
            return null;
        }
    }

    showTab(href) {
        const link = this.tabs().find((tab) => tab.getAttribute('href') === href);
        if (link && window.bootstrap?.Tab) {
            window.bootstrap.Tab.getOrCreateInstance(link).show();
        }
    }

    tabs() {
        return Array.from(this.element.querySelectorAll('[data-bs-toggle="tab"]'));
    }

    activeTab() {
        return this.tabs().find((tab) => tab.classList.contains('active'));
    }

    panels() {
        return Array.from(this.element.querySelectorAll('.deck-scroll'));
    }

    storageKey() {
        return `restore-view:${this.keyValue || window.location.pathname}`;
    }
}
