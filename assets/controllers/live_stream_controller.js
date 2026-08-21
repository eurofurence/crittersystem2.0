import { Controller } from '@hotwired/stimulus';
import { backgroundFetch } from '../js/session.js';
import { subscribe, isDegraded } from '../js/live.js';

/*
 * A region kept current by the server, replacing the timer that used to re-fetch it.
 *
 *   data-live-stream-topic-value="urn:critter:user:<uuid>:notifications"
 *   data-live-stream-url-value="/notifications/bell"
 *
 * Two kinds of update arrive on a topic:
 *
 *   A signal - `{"signal":true}` - meaning "this changed", carrying no data. The region answers by
 *   requesting its own URL, where the ordinary controller authorization runs. This is how anything
 *   whose rendering depends on who is looking is kept correct: the server decides what this viewer
 *   sees, every time, exactly as it did when the page was first built.
 *
 *   A Turbo Stream, applied as-is. Only used for fragments that are identical for every subscriber
 *   of the topic and render without consulting the security context.
 *
 * If the stream cannot stay connected the region falls back to a slow poll, so a hub outage during
 * an event degrades the bounty board and the Info Desk rather than freezing them.
 *
 * `topic` holds one topic or several separated by spaces. The bounty board needs two: the shared
 * one carrying every call, and its own, because a refusal concerns one person while a new call
 * concerns everybody.
 */
export default class extends Controller {
    static values = {
        topic: String,
        url: String,
        event: String,
        fallbackInterval: { type: Number, default: 60000 },
    };

    connect() {
        this.missedWhileHidden = false;
        this.unsubscribers = this.topicValue
            .split(' ')
            .filter((topic) => topic !== '')
            .map((topic) => subscribe(topic, (data) => this.handle(data)));
        this.onState = (event) => this.applyState(event.detail.state);
        this.onVisibility = () => this.catchUp();
        window.addEventListener('live:state', this.onState);
        document.addEventListener('visibilitychange', this.onVisibility);

        this.wasDegraded = isDegraded();
        if (this.wasDegraded) {
            this.startFallback();
        }
        this.scheduleTimedRefresh();
    }

    disconnect() {
        this.unsubscribers?.forEach((unsubscribe) => unsubscribe());
        this.unsubscribers = null;
        window.removeEventListener('live:state', this.onState);
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.stopFallback();
        this.cancelTimedRefresh();
    }

    /**
     * Look again at a moment the server has named.
     *
     * Some state changes on the clock rather than on an event - an operational status override
     * lapses, a shift begins - and nothing happens server-side at that instant, so there is nothing
     * to push. The server knows when it will happen, so the region waits for exactly that moment
     * instead of polling on the chance that it has passed. The refreshed fragment carries the next
     * moment, if there is one, so the chain continues.
     */
    scheduleTimedRefresh() {
        this.cancelTimedRefresh();

        // Read from the fragment, not from this element: refreshing replaces the fragment, so a
        // moment declared on the outer element would be the first one forever and the chain would
        // stop after a single transition.
        const declared = this.element.querySelector('[data-next-transition]')?.dataset.nextTransition;
        if (!declared) {
            return;
        }

        const at = Date.parse(declared);
        if (Number.isNaN(at)) {
            return;
        }

        // A second of slack, so the server is past the boundary when it recomputes. A clock that is
        // behind would otherwise answer with the same state and the region would settle there.
        const delay = Math.max(1000, at - Date.now() + 1000);
        this.refreshTimer = window.setTimeout(() => this.refresh(), delay);
    }

    cancelTimedRefresh() {
        if (this.refreshTimer) {
            window.clearTimeout(this.refreshTimer);
            this.refreshTimer = null;
        }
    }

    /**
     * A signal that arrived while the tab was hidden still has to be honoured.
     *
     * Work is skipped in a hidden tab, but a signal - unlike the timer this replaced - happens once
     * per real change and is never repeated. Dropping it would leave the region stale until the next
     * unrelated change, which for a chat thread means a message that simply never appears.
     */
    catchUp() {
        if (!document.hidden && this.missedWhileHidden) {
            this.missedWhileHidden = false;
            this.refresh();
        }
    }

    /**
     * A dropped connection loses every signal sent while it was down, and the hub does not replay
     * them, so a region that merely resumes listening keeps whatever it was showing when the link
     * failed - indefinitely, on a screen nobody is touching. Coming back therefore costs one
     * re-render, which resynchronises the region with the server before streaming continues.
     *
     * Only on the transition into "connected": applying it on every event would re-render each
     * region whenever any other one reported its state.
     */
    applyState(state) {
        if (state === 'degraded') {
            this.wasDegraded = true;
            this.startFallback();

            return;
        }

        this.stopFallback();
        if (this.wasDegraded) {
            this.wasDegraded = false;
            this.handle(null);
        }
    }

    startFallback() {
        // Announce mode has no fallback on purpose. Its regions are expensive to refresh and hold
        // edit state, so polling them on a timer would be worse than not refreshing: with the hub
        // unreachable they simply behave as they did before there was a hub, updating on the
        // manager's own edits.
        if (this.fallbackTimer || !this.hasUrlValue) {
            return;
        }
        this.fallbackTimer = window.setInterval(() => this.refresh(), this.fallbackIntervalValue);
    }

    stopFallback() {
        if (this.fallbackTimer) {
            window.clearInterval(this.fallbackTimer);
            this.fallbackTimer = null;
        }
    }

    handle(data) {
        // A Turbo Stream is applied directly; anything else is a signal to go and ask.
        //
        // Turbo is reached through the global it installs rather than imported, for the same reason
        // window.bootstrap is (see assets/app.js): assets are served unbundled by AssetMapper, and
        // importing it here would drag a shipped dependency into the test toolchain.
        if (typeof data === 'string' && data.includes('<turbo-stream')) {
            window.Turbo?.renderStreamMessage(data);

            return;
        }

        /*
         * Announce mode. Some regions cannot be refreshed by swapping their markup: the planner and
         * the matrix carry live edit state - a drag in progress, a selection the side panel is
         * acting on - and replacing their DOM underneath would destroy it. They own their own
         * refresh and decide when it is safe, so this only tells them something changed.
         */
        if (this.hasEventValue && this.eventValue !== '') {
            window.dispatchEvent(new CustomEvent(this.eventValue));

            return;
        }

        this.refresh();
    }

    async refresh() {
        if (!this.hasUrlValue) {
            return;
        }
        if (document.hidden) {
            this.missedWhileHidden = true;

            return;
        }

        try {
            const response = await backgroundFetch(this.urlValue, { headers: { Accept: 'text/html' } });

            // Session gone: we are already navigating away, so touch nothing.
            if (response === null) {
                this.stopFallback();

                return;
            }
            if (!response.ok) {
                return;
            }

            const html = await response.text();

            /*
             * Refuse a whole page.
             *
             * Any gate that redirects instead of refusing a background request - the onboarding
             * gate did exactly this - turns into 200 OK carrying a full document, because fetch
             * follows redirects. Assigning that to innerHTML renders an entire page inside a navbar
             * widget and destroys the layout. The server side is fixed, but this guard is what makes
             * the whole class of bug impossible rather than fixed once.
             */
            if (/^\s*(<!doctype|<html)/i.test(html) || html.includes('</html>')) {
                console.warn('Live region received a full document instead of a fragment; ignoring.');
                this.stopFallback();

                return;
            }

            this.element.innerHTML = html;

            // The new fragment carries the next clock-driven moment, if any.
            this.scheduleTimedRefresh();
        } catch (error) {
            /* transient network error - the next signal or fallback tick tries again */
        }
    }
}
