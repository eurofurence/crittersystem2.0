import { Controller } from '@hotwired/stimulus';

/*
 * Smooths the clock labels between renders: "in 12 min", "40 min ago", "2h 15m".
 *
 * Display only. It performs no request and is never the source of a value - the server renders the
 * absolute instant into `datetime`, and this reformats it so a wall display's numbers advance rather
 * than jumping when the region next re-renders. If it never runs, the absolute time it replaces is
 * already in the element and the panel still reads correctly.
 *
 * The controller owns its timer: `disconnect()` ends it, and it stops entirely while the tab is
 * hidden. A board is open for hours and Turbo replaces the body without tearing the document down,
 * so a timer started outside a controller's lifecycle would outlive the page that started it and
 * accumulate one more on every visit.
 */
export default class extends Controller {
    static values = {
        mode: { type: String, default: 'ago' },
    };

    connect() {
        this.absolute = this.element.textContent.trim();
        if (this.absolute && !this.element.title) {
            this.element.title = this.absolute;
        }

        this.at = Date.parse(this.element.getAttribute('datetime') ?? '');
        if (Number.isNaN(this.at)) {
            return;
        }

        this.onVisibility = () => this.sync();
        document.addEventListener('visibilitychange', this.onVisibility);
        this.sync();
    }

    disconnect() {
        this.stop();
        if (this.onVisibility) {
            document.removeEventListener('visibilitychange', this.onVisibility);
            this.onVisibility = undefined;
        }
    }

    sync() {
        if (document.hidden) {
            this.stop();
            return;
        }
        this.render();
        this.start();
    }

    start() {
        if (this.timer) {
            return;
        }
        this.timer = window.setInterval(() => this.render(), 1000);
    }

    stop() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = undefined;
        }
    }

    render() {
        const seconds = Math.round((this.at - Date.now()) / 1000);

        if (this.modeValue === 'since') {
            this.element.textContent = this.duration(Math.max(0, -seconds));
            return;
        }

        this.element.textContent = this.relative(seconds);
    }

    /** Elapsed time as a compact duration, which reads faster at a distance than "2 hours ago". */
    duration(seconds) {
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);

        return hours > 0 ? `${hours}h ${String(minutes % 60).padStart(2, '0')}m` : `${minutes}m`;
    }

    relative(seconds) {
        const locale = document.documentElement.lang || 'en';
        const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
        const magnitude = Math.abs(seconds);

        if (magnitude < 60) {
            return format.format(Math.trunc(seconds), 'second');
        }
        if (magnitude < 3600) {
            return format.format(Math.trunc(seconds / 60), 'minute');
        }
        if (magnitude < 86400) {
            return format.format(Math.trunc(seconds / 3600), 'hour');
        }

        return format.format(Math.trunc(seconds / 86400), 'day');
    }
}
