import { Controller } from '@hotwired/stimulus';
import { alertModal } from '../js/modal.js';
import { backgroundFetch } from '../js/session.js';

/*
 * Standard Planner interaction.
 *
 * Dependency-free pointer handling over a time×day grid:
 *   - drag on empty grid to paint a new draft shift (snaps to the raster);
 *   - drag a block to move it, drag its bottom edge to resize;
 *   - click to select, drag across empty grid to select a range, shift/ctrl to add;
 *   - Delete/Backspace removes the selection.
 *
 * Every mutation posts JSON to the server (which owns validation and draft
 * persistence) and then reloads just the grid, so no flow needs a full refresh.
 */
export default class extends Controller {
    static targets = ['grid', 'dayBody', 'block', 'resizeHandle'];
    static values = {
        department: String,
        timezone: String,
        raster: { type: Number, default: 30 },
        paintUrl: String,
        paintToken: String,
        editToken: String,
        audience: { type: String, default: 'public_volunteer' },
        task: { type: String, default: '' },
        location: { type: String, default: '' },
        mode: { type: String, default: 'select' },
    };

    /*
     * How far the pointer must travel before a press on a block becomes a drag. Below it the press
     * is a click and selects, which is the whole point: a mouse moves a pixel or two under an
     * ordinary click, and treating that as a drag posted a move, reloaded the grid and destroyed the
     * selection the click was making. Managers reported selection as "impossible, takes several
     * tries" because of it.
     */
    static DRAG_THRESHOLD_PX = 4;

    /*
     * Stimulus fires the *TargetConnected callbacks BEFORE connect(), so any state they read has to
     * exist by the end of initialize(). Setting these up in connect() instead threw on the first
     * block of a populated grid, and the exception aborted the controller's connection - taking
     * painting, dragging and the grid refresh down with it on every department that had shifts.
     */
    initialize() {
        this.selected = new Set();
        this.invalid = new Set();
    }

    connect() {
        this.onPointerDown = this.handlePointerDown.bind(this);
        this.onPointerMove = this.handlePointerMove.bind(this);
        this.onPointerUp = this.handlePointerUp.bind(this);
        this.onKeyDown = this.handleKeyDown.bind(this);
        this.onChanged = () => this.reloadGrid();
        this.onRemoteChanged = () => this.queueRemoteReload();
        this.onEditMaybeFinished = () => this.applyRemoteReloadIfIdle();
        this.onSetPaint = (event) => this.applyPaintDefaults(event.detail);
        this.onInvalid = (event) => this.applyInvalid(event.detail?.uuids ?? []);

        this.element.addEventListener('pointerdown', this.onPointerDown);
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        this.element.addEventListener('keydown', this.onKeyDown);
        // Toolbar/modal/panel forms ask us to reload the grid or adjust paint defaults.
        window.addEventListener('planner:changed', this.onChanged);
        window.addEventListener('planner:paint-defaults', this.onSetPaint);
        window.addEventListener('planner:invalid', this.onInvalid);
        window.addEventListener('planner:remote-changed', this.onRemoteChanged);
        document.addEventListener('hidden.bs.modal', this.onEditMaybeFinished);
    }

    disconnect() {
        this.element.removeEventListener('pointerdown', this.onPointerDown);
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        this.element.removeEventListener('keydown', this.onKeyDown);
        window.removeEventListener('planner:changed', this.onChanged);
        window.removeEventListener('planner:paint-defaults', this.onSetPaint);
        window.removeEventListener('planner:invalid', this.onInvalid);
        window.removeEventListener('planner:remote-changed', this.onRemoteChanged);
        document.removeEventListener('hidden.bs.modal', this.onEditMaybeFinished);
        this.clearGesture();
    }

    /**
     * Someone else changed this department.
     *
     * Their change is not applied on arrival. reloadGrid() replaces the grid wholesale, so doing
     * that mid-edit would pull the blocks out from under a drag, empty the side panel a manager was
     * acting through, or refresh the page behind an open modal. The change is remembered and applied
     * at the next moment this manager is not in the middle of something.
     *
     * A manager's own edits are unaffected: those come through planner:changed and still reload
     * immediately, because they are the one who asked.
     */
    queueRemoteReload() {
        this.remotePending = true;
        this.applyRemoteReloadIfIdle();
    }

    /** True while applying a remote change would disrupt what this manager is doing. */
    midEdit() {
        return Boolean(this.gesture)
            || this.selected.size > 0
            || document.querySelector('.modal.show') !== null;
    }

    applyRemoteReloadIfIdle() {
        if (!this.remotePending || this.midEdit()) {
            return;
        }
        this.remotePending = false;
        this.reloadGrid();
    }

    applyPaintDefaults(detail) {
        if (detail.audience !== undefined) {
            this.audienceValue = detail.audience;
        }
        if (detail.task !== undefined) {
            this.taskValue = String(detail.task);
        }
        if (detail.location !== undefined) {
            this.locationValue = String(detail.location);
        }
        if (detail.mode !== undefined) {
            this.modeValue = detail.mode === 'paint' ? 'paint' : 'select';
        }
    }

    // The class lives on the controller element, not on the grid: reloadGrid() replaces the grid
    // wholesale after every edit, which would drop it.
    modeValueChanged() {
        this.element.classList.toggle('is-painting', this.modeValue === 'paint');
    }

    // ---- geometry helpers -------------------------------------------------

    minutesPerPixel(body) {
        return 1440 / body.getBoundingClientRect().height;
    }

    snap(minutes) {
        const r = this.rasterValue;
        return Math.max(0, Math.min(1440, Math.round(minutes / r) * r));
    }

    pointerMinutes(body, clientY) {
        const rect = body.getBoundingClientRect();
        return this.snap((clientY - rect.top) * this.minutesPerPixel(body));
    }

    isoAtMinutes(dayIso, minutes) {
        const [y, m, d] = dayIso.split('-').map(Number);
        const base = new Date(Date.UTC(y, m - 1, d));
        base.setUTCMinutes(base.getUTCMinutes() + minutes);
        const pad = (n) => String(n).padStart(2, '0');
        return `${base.getUTCFullYear()}-${pad(base.getUTCMonth() + 1)}-${pad(base.getUTCDate())}`
            + `T${pad(base.getUTCHours())}:${pad(base.getUTCMinutes())}:00`;
    }

    // ---- gesture start ----------------------------------------------------

    /**
     * Creating a shift is exclusive to paint mode, including over an existing block: a drag in
     * select mode never creates anything, so an imprecise drag on empty grid cannot leave a stray
     * shift behind. The block under the pointer is ignored in paint mode, which is the only way to
     * start a shift running in parallel with one already there.
     */
    handlePointerDown(event) {
        if (event.button !== 0) {
            return;
        }
        const body = event.target.closest('.planner-day-body');
        if (this.modeValue === 'paint') {
            if (body) {
                this.startPaint(event, body);
            }
            return;
        }

        const block = event.target.closest('.planner-block');
        if (block) {
            this.armBlockGesture(event, block);
        } else if (body) {
            this.startMarquee(event);
        }
    }

    startPaint(event, body) {
        event.preventDefault();
        const start = this.pointerMinutes(body, event.clientY);
        this.gesture = { kind: 'paint', armed: true, body, day: body.dataset.plannerDay, start, end: start };
        this.preview = document.createElement('div');
        this.preview.className = 'planner-paint-preview';
        body.appendChild(this.preview);
        this.renderPreview();
    }

    /**
     * Records what a press on a block would do without doing any of it yet. The gesture only becomes
     * a move or a resize once the pointer has actually travelled; until then it is a click, and
     * pointerup selects instead.
     */
    armBlockGesture(event, block) {
        event.preventDefault();
        const body = block.closest('.planner-day-body');
        const topMin = this.snap(parseFloat(block.style.top) / 100 * 1440);
        const durationMin = this.blockDuration(block);
        const resizing = Boolean(event.target.closest('.planner-block-resize'));

        this.gesture = {
            kind: resizing ? 'resize' : 'move',
            armed: false,
            originX: event.clientX,
            originY: event.clientY,
            additive: event.shiftKey || event.ctrlKey || event.metaKey,
            block,
            body,
            topMin,
            durationMin,
            offset: this.pointerMinutes(body, event.clientY) - topMin,
        };
    }

    /**
     * Rubber-band selection, as in a file manager: dragging across empty grid selects every shift
     * the band touches, and shift/ctrl adds them to what is already selected. A press that never
     * moves is a click on empty space and clears the selection.
     */
    startMarquee(event) {
        event.preventDefault();
        this.gesture = {
            kind: 'marquee',
            armed: false,
            originX: event.clientX,
            originY: event.clientY,
            additive: event.shiftKey || event.ctrlKey || event.metaKey,
            base: new Set(this.selected),
        };
    }

    // ---- gesture move -----------------------------------------------------

    handlePointerMove(event) {
        const g = this.gesture;
        if (!g) {
            return;
        }
        if (!g.armed && !this.passedThreshold(g, event)) {
            return;
        }
        if (!g.armed) {
            this.armGesture(g);
        }

        if (g.kind === 'paint') {
            g.end = this.pointerMinutes(g.body, event.clientY);
            this.renderPreview();
        } else if (g.kind === 'move') {
            let top = this.snap(this.pointerMinutes(g.body, event.clientY) - g.offset);
            top = Math.min(top, 1440 - g.durationMin);
            g.block.style.top = `${top / 1440 * 100}%`;
            g.block.style.height = `${g.durationMin / 1440 * 100}%`;
            g.newTop = top;
        } else if (g.kind === 'resize') {
            const end = Math.max(g.topMin + this.rasterValue, this.pointerMinutes(g.body, event.clientY));
            g.block.style.height = `${(end - g.topMin) / 1440 * 100}%`;
            g.newEnd = end;
        } else if (g.kind === 'marquee') {
            this.renderMarquee(event);
            this.applyMarqueeSelection();
        }
    }

    passedThreshold(gesture, event) {
        const threshold = this.constructor.DRAG_THRESHOLD_PX;

        return Math.abs(event.clientX - gesture.originX) > threshold
            || Math.abs(event.clientY - gesture.originY) > threshold;
    }

    armGesture(gesture) {
        gesture.armed = true;
        if (gesture.kind === 'marquee') {
            this.marquee = document.createElement('div');
            this.marquee.className = 'planner-marquee';
            this.gridTarget.appendChild(this.marquee);
            this.element.classList.add('is-marqueeing');

            return;
        }
        gesture.block.classList.add('is-dragging');
    }

    renderPreview() {
        const g = this.gesture;
        const top = Math.min(g.start, g.end);
        const height = Math.max(this.rasterValue, Math.abs(g.end - g.start));
        this.preview.style.top = `${top / 1440 * 100}%`;
        this.preview.style.height = `${height / 1440 * 100}%`;
    }

    /**
     * The band is drawn inside the scroll container and positioned in its coordinate space, so it
     * keeps covering the same shifts when the grid is scrolled under it mid-drag.
     */
    renderMarquee(event) {
        const g = this.gesture;
        const rect = this.gridTarget.getBoundingClientRect();
        const x1 = g.originX - rect.left + this.gridTarget.scrollLeft;
        const y1 = g.originY - rect.top + this.gridTarget.scrollTop;
        const x2 = event.clientX - rect.left + this.gridTarget.scrollLeft;
        const y2 = event.clientY - rect.top + this.gridTarget.scrollTop;

        g.rect = {
            left: Math.min(g.originX, event.clientX),
            right: Math.max(g.originX, event.clientX),
            top: Math.min(g.originY, event.clientY),
            bottom: Math.max(g.originY, event.clientY),
        };

        this.marquee.style.left = `${Math.min(x1, x2)}px`;
        this.marquee.style.top = `${Math.min(y1, y2)}px`;
        this.marquee.style.width = `${Math.abs(x2 - x1)}px`;
        this.marquee.style.height = `${Math.abs(y2 - y1)}px`;
    }

    applyMarqueeSelection() {
        const g = this.gesture;
        const touched = this.blockTargets
            .filter((block) => this.intersects(block.getBoundingClientRect(), g.rect))
            .map((block) => block.dataset.shiftId);

        this.replaceSelection(g.additive ? [...g.base, ...touched] : touched);
    }

    intersects(a, b) {
        return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
    }

    // ---- gesture end ------------------------------------------------------

    handlePointerUp() {
        const g = this.gesture;
        if (!g) {
            return;
        }
        this.gesture = null;

        if (!g.armed) {
            this.finishUnarmed(g);
        } else if (g.kind === 'paint') {
            this.finishPaint(g);
        } else if (g.kind === 'move') {
            this.finishMove(g);
        } else if (g.kind === 'resize') {
            this.finishResize(g);
        } else if (g.kind === 'marquee') {
            this.finishMarquee();
        }

        this.releaseDeferredReload();
    }

    /** A press that never travelled: a click, which selects a block or clears the selection. */
    finishUnarmed(gesture) {
        if (gesture.kind === 'marquee') {
            this.replaceSelection(gesture.additive ? [...gesture.base] : []);

            return;
        }
        if (gesture.block) {
            this.toggleSelect(gesture.block, gesture.additive);
        }
    }

    finishPaint(gesture) {
        const start = Math.min(gesture.start, gesture.end);
        const end = Math.max(gesture.start, gesture.end);
        this.preview?.remove();
        this.preview = null;
        if (end - start >= this.rasterValue) {
            this.paint(gesture.day, start, end);
        }
    }

    /**
     * A drag that ends where it started saves nothing. Posting it anyway cost a request and a grid
     * reload for a shift that did not move, and the reload is what wiped the manager's selection.
     */
    finishMove(gesture) {
        gesture.block.classList.remove('is-dragging');
        if (gesture.newTop === undefined || gesture.newTop === gesture.topMin || !gesture.block.isConnected) {
            return;
        }
        const day = gesture.body.dataset.plannerDay;
        this.moveShift(
            gesture.block,
            this.isoAtMinutes(day, gesture.newTop),
            this.isoAtMinutes(day, gesture.newTop + gesture.durationMin),
        );
    }

    finishResize(gesture) {
        gesture.block.classList.remove('is-dragging');
        const originalEnd = gesture.topMin + gesture.durationMin;
        if (gesture.newEnd === undefined || gesture.newEnd === originalEnd || !gesture.block.isConnected) {
            return;
        }
        this.moveShift(
            gesture.block,
            gesture.block.dataset.start,
            this.isoAtMinutes(gesture.body.dataset.plannerDay, gesture.newEnd),
        );
    }

    finishMarquee() {
        this.marquee?.remove();
        this.marquee = null;
        this.element.classList.remove('is-marqueeing');
    }

    clearGesture() {
        this.preview?.remove();
        this.preview = null;
        this.marquee?.remove();
        this.marquee = null;
        this.element.classList.remove('is-marqueeing');
        this.gesture = null;
    }

    /**
     * The moments an edit can finish.
     *
     * Called after a gesture ends and after the selection changes, which together with the modal
     * listener in connect() cover every state {@see midEdit()} holds a remote change back for. A
     * deferred change that never found one of these would sit unapplied until the next local edit.
     */
    releaseDeferredReload() {
        this.applyRemoteReloadIfIdle();
    }

    blockDuration(block) {
        return this.snap(parseFloat(block.style.height) / 100 * 1440) || this.rasterValue;
    }

    // ---- selection & delete ----------------------------------------------

    /**
     * Enter and Space select the focused block. A div with role="button" gets no click from the
     * keyboard, and selection is driven by pointer events, so without this the grid is reachable by
     * keyboard but cannot be acted on.
     */
    handleKeyDown(event) {
        if ((event.key === 'Delete' || event.key === 'Backspace') && this.selected.size > 0) {
            event.preventDefault();
            this.deleteSelected();

            return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        const block = event.target.closest?.('.planner-block');
        if (block) {
            event.preventDefault();
            this.toggleSelect(block, event.shiftKey || event.ctrlKey || event.metaKey);
        }
    }

    blockTargetConnected(block) {
        // Runs for every block the reloaded grid brings in, which is what re-applies the outlines
        // and the selection.
        block.classList.toggle('planner-block-invalid', this.invalid.has(block.dataset.shiftId));
        block.classList.toggle('is-selected', this.selected.has(block.dataset.shiftId));
    }

    applyInvalid(uuids) {
        this.invalid = new Set(uuids);
        this.blockTargets.forEach((block) => {
            block.classList.toggle('planner-block-invalid', this.invalid.has(block.dataset.shiftId));
        });
    }

    toggleSelect(block, additive) {
        const id = block.dataset.shiftId;
        const next = additive ? new Set(this.selected) : new Set();
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        this.replaceSelection(next);
    }

    /**
     * The selection is held as shift uuids rather than as elements. Every edit replaces the grid, so
     * a selection of nodes pointed at blocks that had been detached: the panel then acted on shifts
     * the manager could no longer see highlighted, and a click arriving just after a reload selected
     * a node that was no longer in the document at all.
     */
    replaceSelection(ids) {
        const next = new Set(ids);
        if (next.size === this.selected.size && [...next].every((id) => this.selected.has(id))) {
            return;
        }

        this.selected = next;
        this.blockTargets.forEach((block) => {
            block.classList.toggle('is-selected', next.has(block.dataset.shiftId));
        });
        this.dispatch('selection', { target: window, detail: { ids: [...next] } });
        this.releaseDeferredReload();
    }

    blockFor(id) {
        return this.blockTargets.find((block) => block.dataset.shiftId === id) ?? null;
    }

    // ---- server calls -----------------------------------------------------

    async paint(day, startMin, endMin) {
        await this.post(this.paintUrlValue, {
            _token: this.paintTokenValue,
            department: this.departmentValue,
            audience: this.audienceValue,
            task: this.taskValue,
            location: this.locationValue,
            intervals: [{ start: this.isoAtMinutes(day, startMin), end: this.isoAtMinutes(day, endMin) }],
        });
    }

    async moveShift(block, start, end) {
        await this.post(block.dataset.moveUrl, { _token: this.editTokenValue, start, end });
    }

    async deleteSelected() {
        const urls = [...this.selected]
            .map((id) => this.blockFor(id)?.dataset.deleteUrl)
            .filter(Boolean);
        this.replaceSelection([]);
        for (const url of urls) {
            await this.post(url, { _token: this.editTokenValue });
        }
    }

    async post(url, body) {
        try {
            const response = await backgroundFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (response === null) {
                return;
            }
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                await alertModal(data.error || 'The change could not be saved. Reload and try again.');
            }
        } catch (e) {
            console.error('Save request failed.', e);
            await alertModal('Network error while saving. Reload and try again.');
        }
        await this.reloadGrid();
    }

    async reloadGrid() {
        try {
            const response = await backgroundFetch(window.location.href);
            if (response === null) {
                return;
            }
            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const fresh = parsed.querySelector('[data-planner-target="grid"]');
            const current = this.hasGridTarget ? this.gridTarget : this.element.querySelector('[data-planner-target="grid"]');
            if (fresh && current) {
                /*
                 * The grid element is the horizontal scroll container, so replacing it would land the
                 * manager back at day one after every paint. Carry the offsets over to the new element;
                 * the browser clamps them if the grid got narrower.
                 */
                const left = current.scrollLeft;
                const top = current.scrollTop;
                const pageX = window.scrollX;
                const pageY = window.scrollY;

                current.replaceWith(fresh);
                /*
                 * Anything in progress was pointing at blocks that have just been detached. Left
                 * alone, the drag continued against the old element and its pointerup posted a
                 * position computed from it, which is how a shift being dragged jumped to an
                 * unrelated time whenever a refresh landed mid-gesture.
                 */
                this.clearGesture();
                this.pruneSelection();

                fresh.scrollLeft = left;
                fresh.scrollTop = top;
                window.scrollTo(pageX, pageY);
            }

            this.refreshPublishBar(parsed);
        } catch (e) {
            console.error('Refresh failed; falling back to a full page load.', e);
            window.location.reload();
        }
    }

    /**
     * Shifts the reload did not bring back are gone (a batch delete removes them outright), so they
     * leave the selection and the panel stops offering to act on them. What survived stays selected:
     * losing the selection on every refresh is the behaviour managers complained about.
     */
    pruneSelection() {
        const alive = this.blockTargets.map((block) => block.dataset.shiftId);

        this.replaceSelection([...this.selected].filter((id) => alive.includes(id)));
    }

    refreshPublishBar(parsed) {
        const fresh = parsed.querySelector('#planner-publish-bar');
        const current = document.querySelector('#planner-publish-bar');
        if (fresh && current) {
            current.replaceWith(fresh);
        }
    }
}
