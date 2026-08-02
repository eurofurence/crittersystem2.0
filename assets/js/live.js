/*
 * One Mercure connection for the whole page.
 *
 * Every live region subscribes here rather than opening its own EventSource. Over HTTP/1.1 a browser
 * allows only about six connections per origin, and the navbar alone has two live regions on every
 * page - one connection each would starve ordinary navigation. Mercure accepts any number of `topic`
 * parameters on a single subscription, so one connection carries them all.
 *
 * Which topics the connection is allowed to carry is not decided here. The hub reads that from the
 * subscriber token in the mercureAuthorization cookie, which is signed server-side and which this
 * code cannot see or alter. Asking for a topic the token does not name simply yields nothing.
 */

const HUB_META = 'mercure-hub';
const FAILURES_BEFORE_DEGRADED = 3;

const handlers = new Map(); // topic -> Set<handler>
let source = null;
let failures = 0;
let degraded = false;
let reopenQueued = false;

function hubUrl() {
    return document.querySelector(`meta[name="${HUB_META}"]`)?.content || '';
}

/** True while the stream is not delivering, so regions know to fall back to polling. */
export function isDegraded() {
    return degraded;
}

function announce(state) {
    window.dispatchEvent(new CustomEvent('live:state', { detail: { state } }));
}

function setDegraded(value) {
    if (degraded === value) {
        return;
    }
    degraded = value;
    announce(value ? 'degraded' : 'connected');
}

function close() {
    if (source) {
        source.close();
        source = null;
    }
}

/**
 * Coalesce topic-set changes into one reconnection.
 *
 * The topic list is part of the subscription URL, so every region that connects or disconnects
 * changes it. Stimulus connects them one after another, and reopening per region would open and
 * immediately discard a connection for each - on a page with four live regions, four connections
 * for one useful subscription. A microtask is enough to let them all arrive first.
 */
function scheduleReopen() {
    if (reopenQueued) {
        return;
    }
    reopenQueued = true;
    queueMicrotask(() => {
        reopenQueued = false;
        reopen();
    });
}

/**
 * (Re)open the connection for the current topic set.
 *
 * Called whenever a region connects or disconnects, because the topic list is part of the URL and
 * Mercure has no way to add one to a live subscription.
 */
function reopen() {
    close();

    const topics = [...handlers.keys()];
    if (topics.length === 0) {
        return;
    }

    /*
     * No hub configured for this deployment. Go straight to the fallback rather than opening a
     * connection that cannot succeed: a failing EventSource writes a SEVERE entry to the browser
     * console on every retry, which is noise the user cannot act on and which buries real errors.
     * The regions still update, just on a timer.
     */
    const hub = hubUrl();
    if (hub === '') {
        setDegraded(true);

        return;
    }

    const url = new URL(hub, window.location.origin);
    topics.forEach((topic) => url.searchParams.append('topic', topic));

    // The token travels as a cookie scoped to the hub path, so the request must carry credentials.
    source = new EventSource(url, { withCredentials: true });

    source.onopen = () => {
        failures = 0;
        setDegraded(false);
    };

    source.onmessage = (event) => dispatch(event);

    source.onerror = () => {
        // EventSource reconnects on its own; we only count how often it has failed to stay up, so a
        // hub that is genuinely gone eventually hands the page over to the polling fallback.
        failures += 1;
        if (failures >= FAILURES_BEFORE_DEGRADED) {
            setDegraded(true);
        }
    };
}

/**
 * Deliver an update to the regions listening on its topic.
 *
 * Mercure does not repeat the topic in the SSE frame, so a signal names its own topic in its
 * payload (see App\Mercure\UpdatePublisher::signal). A payload that names none - a Turbo Stream, or
 * anything unparseable - goes to every region: a handler answers by re-fetching its own endpoint,
 * so a spurious wake-up costs one request and can never show another topic's data.
 */
function dispatch(event) {
    let topic = null;
    try {
        topic = JSON.parse(event.data)?.topic ?? null;
    } catch (error) {
        /* not JSON - a rendered fragment */
    }

    const targets = topic && handlers.has(topic) ? [handlers.get(topic)] : [...handlers.values()];
    targets.filter(Boolean).forEach((set) => set.forEach((handler) => {
        try {
            handler(event.data);
        } catch (error) {
            console.error('Live update handler failed.', error);
        }
    }));
}

/**
 * Listen on a topic. Returns the unsubscribe function; callers MUST call it on disconnect or the
 * connection keeps a dead region's topic (and its handler) alive for the life of the page.
 */
export function subscribe(topic, handler) {
    if (!topic) {
        return () => {};
    }

    if (!handlers.has(topic)) {
        handlers.set(topic, new Set());
    }
    handlers.get(topic).add(handler);
    scheduleReopen();

    return () => {
        const set = handlers.get(topic);
        if (!set) {
            return;
        }
        set.delete(handler);
        if (set.size === 0) {
            handlers.delete(topic);
        }
        scheduleReopen();
    };
}

/** Test seam: drop all state between cases. */
export function reset() {
    close();
    handlers.clear();
    failures = 0;
    degraded = false;
    reopenQueued = false;
}
