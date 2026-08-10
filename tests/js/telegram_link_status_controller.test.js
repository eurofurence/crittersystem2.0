import { describe, it, expect, afterEach, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import TelegramLinkStatusController from '../../assets/controllers/telegram_link_status_controller.js';

/*
 * The link-status poll used to be an inline script whose setTimeout rescheduled itself. Turbo swaps
 * the body without tearing the document down, so the loop outlived its page and a new one started on
 * every visit - volunteers hit the status endpoint every three seconds, several loops at a time, for
 * as long as the tab stayed open.
 *
 * These protect the three things that keep that from coming back: the timer dies with the element,
 * an expired code stops the poll for good, and a confirmed link reloads exactly once.
 */

let application;

function markup({ expiresAt = '' } = {}) {
    return `<p id="w"
        data-controller="telegram-link-status"
        data-telegram-link-status-url-value="/onboarding/telegram/status"
        data-telegram-link-status-interval-value="3000"
        data-telegram-link-status-expires-at-value="${expiresAt}">waiting</p>`;
}

async function start(options = {}) {
    document.body.innerHTML = markup(options);
    application = Application.start();
    application.register('telegram-link-status', TelegramLinkStatusController);
    await vi.waitFor(() => {
        const c = application.getControllerForElementAndIdentifier(
            document.querySelector('#w'),
            'telegram-link-status',
        );
        if (!c) {
            throw new Error('not connected');
        }

        return c;
    });

    return application.getControllerForElementAndIdentifier(
        document.querySelector('#w'),
        'telegram-link-status',
    );
}

function stubStatus(linked) {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ linked }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    }));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

describe('telegram link status poll', () => {
    afterEach(() => {
        application?.stop();
        vi.unstubAllGlobals();
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('polls the status endpoint while the link is pending', async () => {
        const fetchMock = stubStatus(false);
        await start();

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(String(fetchMock.mock.calls[0][0])).toContain('/onboarding/telegram/status');
    });

    it('stops polling when the element goes away, so it cannot outlive its page', async () => {
        const fetchMock = stubStatus(false);
        const controller = await start();
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());

        controller.disconnect();
        const callsAtDisconnect = fetchMock.mock.calls.length;

        await new Promise((resolve) => setTimeout(resolve, 120));

        expect(controller.timer).toBeNull();
        expect(fetchMock.mock.calls.length).toBe(callsAtDisconnect);
    });

    it('schedules nothing further once disconnected', async () => {
        stubStatus(false);
        const controller = await start();

        controller.disconnect();
        controller.schedule(0);

        expect(controller.timer).toBeNull();
    });

    it('gives up once the pending code has expired', async () => {
        const fetchMock = stubStatus(false);
        const controller = await start({ expiresAt: new Date(Date.now() - 1000).toISOString() });

        await controller.check();

        expect(controller.stopped).toBe(true);
        expect(controller.timer).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('keeps polling while the code is still valid', async () => {
        stubStatus(false);
        const controller = await start({ expiresAt: new Date(Date.now() + 60000).toISOString() });

        expect(controller.expired()).toBe(false);
    });

    it('reloads once the bot has confirmed the link, and stops', async () => {
        stubStatus(true);
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...window.location, reload },
        });

        const controller = await start();
        await vi.waitFor(() => expect(reload).toHaveBeenCalledTimes(1));

        expect(controller.stopped).toBe(true);
        expect(controller.timer).toBeNull();
    });

    it('does not poll a hidden tab', async () => {
        const fetchMock = stubStatus(false);
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });

        const controller = await start();
        await new Promise((resolve) => setTimeout(resolve, 60));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(controller.timer).toBeNull();

        Object.defineProperty(document, 'hidden', { configurable: true, value: false });
    });

    it('retries after a network failure instead of dying', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new Error('offline');
        }));
        const controller = await start();

        await controller.check();

        expect(controller.stopped).toBe(false);
        expect(controller.timer).not.toBeNull();
    });
});
