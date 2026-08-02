import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import LiveStreamController from '../../assets/controllers/live_stream_controller.js';
import { reset } from '../../assets/js/live.js';

/*
 * The live region, driven through a real Stimulus Application.
 *
 * The behaviour that matters most here cannot be seen from PHP at all: what the controller does with
 * a response. A polling widget that assigned whatever came back to innerHTML rendered an entire
 * redirected page inside the navbar, and the PHPUnit suite stayed green throughout, because the
 * server had answered every request correctly as far as it was concerned.
 */

const HUB = 'https://hub.test/.well-known/mercure';
const TOPIC = 'urn:critter:user:abc:notifications';

let application;
let sources;

class FakeEventSource {
    constructor(url) {
        this.url = url;
        this.closed = false;
        sources.push(this);
    }

    close() {
        this.closed = true;
    }

    emit(data) {
        this.onmessage?.({ data, lastEventId: '' });
    }

    fail() {
        this.onerror?.(new Event('error'));
    }

    open() {
        this.onopen?.(new Event('open'));
    }
}

/*
 * Set document.hidden as an own property rather than spying on the getter. A restored getter spy
 * leaves happy-dom's document reporting hidden === true, which silently disabled every later case
 * that expected a fetch.
 */
function setHidden(value) {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => value });
}

function clearHidden() {
    delete document.hidden;
}

function mount(markup) {
    document.body.innerHTML = markup;
    application = Application.start();
    application.register('live-stream', LiveStreamController);

    return new Promise((resolve) => setTimeout(resolve, 0));
}

function region(extra = '') {
    return `
        <meta name="mercure-hub" content="${HUB}">
        <div id="bell"
             data-controller="live-stream"
             data-live-stream-topic-value="${TOPIC}"
             data-live-stream-url-value="/notifications/bell"
             ${extra}>original</div>`;
}

beforeEach(() => {
    sources = [];
    vi.stubGlobal('EventSource', FakeEventSource);
    // shouldAdvanceTime keeps awaited microtask/0ms turns resolving, so the Stimulus lifecycle and
    // the controller's own fetches still complete while the fallback interval stays under control.
    vi.useFakeTimers({ shouldAdvanceTime: true });
});

afterEach(async () => {
    // Empty the DOM first and let Stimulus observe it: disconnect() is what removes the window
    // listener and clears the fallback timer, and stopping the application before that leaves both
    // behind to interfere with the next case.
    document.body.innerHTML = '';
    await new Promise((resolve) => setTimeout(resolve, 0));

    application?.stop();
    reset();
    clearHidden();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('live stream region', () => {
    it('subscribes to its topic on the shared connection', async () => {
        await mount(region());

        expect(sources).toHaveLength(1);
        expect(sources[0].url.toString()).toContain(encodeURIComponent(TOPIC));
    });

    it('re-fetches its own endpoint when a signal arrives', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            new Response('<span>3 unread</span>', { status: 200 }),
        ));
        await mount(region());

        sources[0].emit('{"signal":true}');
        await vi.waitFor(() => expect(document.getElementById('bell').innerHTML).toContain('3 unread'));

        expect(fetch).toHaveBeenCalledWith('/notifications/bell', expect.objectContaining({
            credentials: 'same-origin',
        }));
    });

    /*
     * The guard that makes the reported breakage impossible rather than merely fixed. A gate that
     * redirects a background request produces 200 OK carrying a whole document, because fetch
     * follows redirects. Injecting that renders a full page inside a navbar widget.
     */
    it('refuses a full HTML document and leaves the region untouched', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            new Response('<!DOCTYPE html><html><body>the whole onboarding page</body></html>', { status: 200 }),
        ));
        await mount(region());

        sources[0].emit('{"signal":true}');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await Promise.resolve();

        expect(document.getElementById('bell').innerHTML).toBe('original');
    });

    it('refuses a document even without a doctype', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            new Response('<html><body>login</body></html>', { status: 200 }),
        ));
        await mount(region());

        sources[0].emit('{"signal":true}');
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await Promise.resolve();

        expect(document.getElementById('bell').innerHTML).toBe('original');
    });

    /* Several regions share one connection, so a signal must wake only the one it names. */
    it('routes a signal to the region that owns the topic', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<span>fresh</span>', { status: 200 })));
        await mount(`
            <meta name="mercure-hub" content="${HUB}">
            <div id="bell" data-controller="live-stream"
                 data-live-stream-topic-value="${TOPIC}"
                 data-live-stream-url-value="/notifications/bell">bell</div>
            <div id="status" data-controller="live-stream"
                 data-live-stream-topic-value="urn:critter:user:abc:status"
                 data-live-stream-url-value="/status">status</div>`);

        // One connection carries both topics.
        expect(sources).toHaveLength(1);
        expect(sources[0].url.toString()).toContain(encodeURIComponent(TOPIC));
        expect(sources[0].url.toString()).toContain(encodeURIComponent('urn:critter:user:abc:status'));

        sources[0].emit(JSON.stringify({ signal: true, topic: TOPIC }));
        await vi.waitFor(() => expect(document.getElementById('bell').innerHTML).toContain('fresh'));

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledWith('/notifications/bell', expect.anything());
        expect(document.getElementById('status').innerHTML).toBe('status');
    });

    /*
     * A signal fires once per real change and is never repeated, unlike the timer this replaced.
     * Dropping one because the tab was hidden leaves the region stale until some unrelated change
     * comes along - for a chat thread, a message that simply never appears.
     */
    it('honours a signal that arrived while the tab was hidden', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<span>late</span>', { status: 200 })));
        await mount(region());

        setHidden(true);
        sources[0].emit(JSON.stringify({ signal: true, topic: TOPIC }));
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();

        setHidden(false);
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.waitFor(() => expect(document.getElementById('bell').innerHTML).toContain('late'));
    });

    it('does not re-fetch when the payload is a Turbo Stream', async () => {
        vi.stubGlobal('fetch', vi.fn());
        await mount(region());

        sources[0].emit('<turbo-stream action="replace" target="bell"></turbo-stream>');

        expect(fetch).not.toHaveBeenCalled();
    });

    it('falls back to polling only after three consecutive failures', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<span>x</span>', { status: 200 })));
        await mount(region('data-live-stream-fallback-interval-value="1000"'));

        sources[0].fail();
        sources[0].fail();
        vi.advanceTimersByTime(3000);
        expect(fetch).not.toHaveBeenCalled();

        sources[0].fail();
        vi.advanceTimersByTime(1000);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('stops polling once the stream reconnects', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<span>x</span>', { status: 200 })));
        await mount(region('data-live-stream-fallback-interval-value="1000"'));

        sources[0].fail();
        sources[0].fail();
        sources[0].fail();
        vi.advanceTimersByTime(1000);
        expect(fetch).toHaveBeenCalledTimes(1);

        sources[0].open();
        vi.advanceTimersByTime(5000);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    /* A region that leaves its topic (and handler) behind keeps a dead node alive for the page. */
    it('unsubscribes and clears its timer on disconnect', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<span>x</span>', { status: 200 })));
        await mount(region('data-live-stream-fallback-interval-value="1000"'));

        sources[0].fail();
        sources[0].fail();
        sources[0].fail();

        document.getElementById('bell').remove();
        await new Promise((resolve) => setTimeout(resolve, 0));

        vi.advanceTimersByTime(5000);
        expect(fetch).not.toHaveBeenCalled();
        expect(sources.at(-1).closed).toBe(true);
    });
});
